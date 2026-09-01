<?php

namespace App\Modules\Payment\Services;

use App\Modules\Notification\Services\OrderNotifier;
use App\Modules\Order\Models\Order;
use App\Modules\Payment\Contracts\PaymentGateway;
use App\Modules\Payment\Models\Payment;
use App\Modules\Payment\Models\Refund;
use App\Modules\User\Models\User;
use App\Modules\Wallet\Enums\TransactionReason;
use App\Modules\Wallet\Services\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Giving money back.
 *
 * The design draws «قيد المراجعة» on a refund, which settles the question of
 * whether one is automatic: it is requested, a person decides, and only then does
 * money move.
 *
 * The ceiling is what was actually captured, not what the order says it cost. An
 * order marked paid in cash has no captured payment behind it, and refunding
 * against a figure nobody ever collected would create money.
 */
class RefundService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly PaymentGateway $gateway,
        private readonly PaymentService $payments,
    ) {}

    /**
     * «طلب استرداد».
     *
     * @throws RuntimeException
     */
    public function request(Order $order, User $customer, float $amount, string $reason, ?string $note = null): Refund
    {
        // Checked before the amount, because the amount is derived from it when
        // the customer omits one — and telling somebody to «enter an amount
        // greater than zero» when the truth is that nothing is refundable sends
        // them to fix the wrong thing.
        $refundable = $this->refundableAmount($order);

        if ($refundable <= 0) {
            throw new RuntimeException('nothing_to_refund');
        }

        if ($amount <= 0) {
            throw new RuntimeException('amount_must_be_positive');
        }

        if ($amount - 0.001 > $refundable) {
            throw new RuntimeException('exceeds_refundable_amount');
        }

        if (Refund::where('order_id', $order->id)->pending()->exists()) {
            // A second open request would let two reviewers approve the same
            // money twice.
            throw new RuntimeException('refund_already_pending');
        }

        return Refund::create([
            'order_id' => $order->id,
            'user_id' => $customer->id,
            'payment_id' => Payment::where('order_id', $order->id)->captured()->value('id'),
            'amount' => round($amount, 2),
            'reason' => $reason,
            'note' => $note,
            'status' => Refund::PENDING,
        ]);
    }

    /**
     * A person decides yes, and where the money goes.
     */
    public function approve(Refund $refund, User $reviewer, string $destination, ?string $note = null): Refund
    {
        if (! $refund->isPending()) {
            throw new RuntimeException('refund_not_pending');
        }

        return DB::transaction(function () use ($refund, $reviewer, $destination, $note) {
            $refund->update([
                'status' => Refund::APPROVED,
                'destination' => $destination,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);

            $settled = $this->settle($refund->refresh());

            $this->announce($settled);

            return $settled;
        });
    }

    public function reject(Refund $refund, User $reviewer, ?string $note = null): Refund
    {
        if (! $refund->isPending()) {
            throw new RuntimeException('refund_not_pending');
        }

        $refund->update([
            'status' => Refund::REJECTED,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_note' => $note,
        ]);

        $this->announce($refund->refresh());

        return $refund->refresh();
    }

    /**
     * Actually move it.
     *
     * A wallet credit lands instantly; a gateway refund does not, and leaving the
     * refund `approved` until the provider confirms is what stops us telling a
     * customer money is back when it is in flight.
     */
    public function settle(Refund $refund): Refund
    {
        if ($refund->status !== Refund::APPROVED) {
            throw new RuntimeException('refund_not_approved');
        }

        $customer = $refund->customer;

        if ($refund->destination === Refund::TO_WALLET) {
            $this->wallets->credit(
                $customer,
                (float) $refund->amount,
                TransactionReason::Refund,
                $refund,
                __('Refund for order :code', ['code' => $refund->order?->code])
            );

            $refund->update(['status' => Refund::SETTLED, 'settled_at' => now()]);

            return $refund->refresh();
        }

        $payment = $refund->payment;

        if (! $payment || ! $payment->provider_reference) {
            throw new RuntimeException('no_payment_to_refund_against');
        }

        $sent = $this->gateway->refund($payment->provider_reference, (float) $refund->amount);

        if (! $sent) {
            // Left approved, not failed: somebody has to chase it, and marking it
            // done would remove it from the only queue that would surface it.
            return $refund->refresh();
        }

        $refund->update(['status' => Refund::SETTLED, 'settled_at' => now()]);

        return $refund->refresh();
    }

    /**
     * A decision the customer asked for is a decision they should hear about.
     */
    private function announce(Refund $refund): void
    {
        try {
            app(OrderNotifier::class)->refundDecided($refund);
        } catch (\Throwable $e) {
            Log::warning('[notifications] refund decision', [
                'refund' => $refund->id, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * How much of this order could still be given back.
     *
     * Captured minus already refunded. Cash orders have nothing captured, so they
     * refund to the wallet or not at all — refunding against money no gateway ever
     * took would create it.
     */
    public function refundableAmount(Order $order): float
    {
        $captured = $this->payments->capturedTotal($order);

        // A cash order that was collected at the door is refundable too, but only
        // into the wallet — there is no card to send it back to.
        if ($captured <= 0 && $order->payment_status === 'paid') {
            $captured = $order->payableTotal();
        }

        $alreadyRefunded = (float) Refund::where('order_id', $order->id)
            ->whereIn('status', [Refund::APPROVED, Refund::SETTLED])
            ->sum('amount');

        return round(max($captured - $alreadyRefunded, 0), 2);
    }
}
