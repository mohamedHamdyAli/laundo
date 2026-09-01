<?php

namespace App\Modules\Payment\Services;

use App\Modules\Order\Models\Order;
use App\Modules\Payment\Contracts\PaymentGateway;
use App\Modules\Payment\Data\ChargeRequest;
use App\Modules\Payment\Data\WebhookEvent;
use App\Modules\Payment\Enums\PaymentMethod;
use App\Modules\Payment\Enums\PaymentStatus;
use App\Modules\Payment\Models\Payment;
use App\Modules\User\Models\User;
use App\Modules\Wallet\Enums\TransactionReason;
use App\Modules\Wallet\Services\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Taking money for an order.
 *
 * The rule that shapes everything here: **a payment is settled by the webhook and
 * by nothing else.** Not by the redirect the customer comes back through — they
 * can close that tab, or never open it — and not by the gateway's optimistic
 * reply to `charge()`, which only means the request was accepted.
 *
 * The second rule follows from the first: the webhook must be safe to receive
 * twice. Providers retry. The unique key on (provider, provider_reference) plus
 * the status check in `capture()` is what makes a replay a no-op instead of a
 * second capture.
 *
 * Cash never comes through here at all. It is collected at the door and settled
 * by P8's `TaskService`, which already marks an order paid only when the whole
 * amount arrives.
 */
class PaymentService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly WalletService $wallets,
    ) {}

    /**
     * Start paying for an order.
     *
     * @throws RuntimeException
     */
    public function initiate(Order $order, User $customer, PaymentMethod $method): Payment
    {
        if ($order->payment_status === 'paid') {
            throw new RuntimeException('already_paid');
        }

        if (! $order->hasFinalPrice()) {
            // Paying an estimate would bind the customer to a figure the laundry
            // has not agreed to either.
            throw new RuntimeException('no_final_price');
        }

        if (! $method->usesGateway()) {
            // Cash is a choice, not a transaction: nothing is charged now, and
            // the driver settles it at the door.
            $order->update(['payment_method' => $method->value]);

            throw new RuntimeException('cash_is_collected_on_delivery');
        }

        $amount = $order->payableTotal();

        if ($amount <= 0) {
            throw new RuntimeException('nothing_to_pay');
        }

        // An earlier attempt that is still open would leave two live references
        // for one order, and whichever webhook landed second would look like a
        // duplicate payment.
        $this->abandonOpenAttempts($order);

        // The wallet is our own ledger, not a provider: the debit either succeeds
        // now or it does not, and there is no webhook to wait for. It still ends
        // at capture(), so there remains exactly one place an order becomes paid.
        if ($method === PaymentMethod::Wallet) {
            return $this->payFromWallet($order, $customer, $amount);
        }

        $result = $this->gateway->charge(new ChargeRequest(
            order: $order,
            amount: $amount,
            method: $method->value,
            callbackUrl: url('/api/v1/payments/webhook/'.$this->gateway->name()),
        ));

        return DB::transaction(function () use ($order, $customer, $method, $amount, $result) {
            $payment = Payment::create([
                'order_id' => $order->id,
                'user_id' => $customer->id,
                'provider' => $this->gateway->name(),
                'method' => $method,
                'provider_reference' => $result->providerReference,
                'amount' => $amount,
                'status' => $result->accepted ? PaymentStatus::Pending : PaymentStatus::Failed,
                'failed_at' => $result->accepted ? null : now(),
                'failure_reason' => $result->failureReason,
                'payload' => $result->payload,
            ]);

            $order->update(['payment_method' => $method->value]);

            // A provider that settles inline still goes through capture(), so
            // there is exactly one place an order becomes paid.
            if ($result->accepted && $result->settledImmediately && $result->providerReference) {
                $this->capture($payment->refresh(), $amount);
            }

            return $payment->refresh();
        });
    }

    /**
     * Act on what a provider told us.
     *
     * Idempotent by design: an unknown reference is ignored rather than guessed
     * at, and an already-captured payment is left alone.
     */
    public function handleWebhook(WebhookEvent $event): ?Payment
    {
        $payment = Payment::where('provider', $this->gateway->name())
            ->where('provider_reference', $event->providerReference)
            ->first();

        if (! $payment) {
            // Not ours, or for an attempt we never recorded. Silently ignored —
            // erroring would make a provider retry forever.
            return null;
        }

        return match ($event->type) {
            WebhookEvent::CAPTURED => $this->capture($payment, $event->amount, $event->payload),
            WebhookEvent::FAILED => $this->fail($payment, $event->reason ?? 'declined', $event->payload),
            WebhookEvent::REFUNDED => $this->markRefunded($payment, $event->payload),
            default => $payment,
        };
    }

    /**
     * The one place an order becomes paid.
     */
    public function capture(Payment $payment, ?float $amount = null, array $payload = []): Payment
    {
        if ($payment->status->isSettled()) {
            // A replayed webhook. Not an error — providers retry — but not a
            // second capture either.
            return $payment;
        }

        if ($payment->status === PaymentStatus::Failed) {
            throw new RuntimeException('cannot_capture_failed_payment');
        }

        return DB::transaction(function () use ($payment, $amount, $payload) {
            $payment->update([
                'status' => PaymentStatus::Captured,
                'captured_at' => now(),
                // The provider's figure wins if it sent one: it is the amount
                // that actually moved, and ours is only what we asked for.
                'amount' => $amount !== null ? round($amount, 2) : $payment->amount,
                'payload' => $payload ?: $payment->payload,
            ]);

            $order = $payment->order;

            if ($order && $order->payment_status !== 'paid') {
                $order->update([
                    'payment_status' => 'paid',
                    'paid_at' => now(),
                    'payment_method' => $payment->method->value,
                ]);
            }

            return $payment->refresh();
        });
    }

    public function fail(Payment $payment, string $reason, array $payload = []): Payment
    {
        if ($payment->status->isSettled()) {
            // Captured money does not un-capture because a later message says so.
            // A reversal is a refund, and refunds are P9b.
            return $payment;
        }

        $payment->update([
            'status' => PaymentStatus::Failed,
            'failed_at' => now(),
            'failure_reason' => $reason,
            'payload' => $payload ?: $payment->payload,
        ]);

        return $payment->refresh();
    }

    /**
     * Whether the order has been paid in full by anything.
     *
     * Sums captured payments rather than trusting `orders.payment_status`, so a
     * part-payment reads as what it is.
     */
    public function capturedTotal(Order $order): float
    {
        return (float) Payment::where('order_id', $order->id)->captured()->sum('amount');
    }

    private function markRefunded(Payment $payment, array $payload = []): Payment
    {
        $payment->update(['status' => PaymentStatus::Refunded, 'payload' => $payload ?: $payment->payload]);

        $order = $payment->order;

        if ($order) {
            $order->update(['payment_status' => 'refunded']);
        }

        return $payment->refresh();
    }

    /**
     * Spend the customer's own balance.
     */
    private function payFromWallet(Order $order, User $customer, float $amount): Payment
    {
        return DB::transaction(function () use ($order, $customer, $amount) {
            $payment = Payment::create([
                'order_id' => $order->id,
                'user_id' => $customer->id,
                'provider' => 'wallet',
                'method' => PaymentMethod::Wallet,
                // Its own reference, so the unique key still holds and a wallet
                // payment is as traceable as a card one.
                //
                // Random rather than timestamped: a retry after a failed attempt
                // lands in the same second, and a second-resolution reference
                // collides on the unique key — which is a constraint violation
                // where the customer should have seen a second chance.
                'provider_reference' => 'WLT-'.$order->code.'-'.strtoupper(Str::random(12)),
                'amount' => $amount,
                'status' => PaymentStatus::Pending,
            ]);

            try {
                $this->wallets->debit(
                    $customer,
                    $amount,
                    TransactionReason::OrderPayment,
                    $order,
                    __('Payment for order :code', ['code' => $order->code])
                );
            } catch (RuntimeException $e) {
                // An insufficient balance is a normal outcome, not an exception
                // the customer should see as a crash.
                $this->fail($payment, $e->getMessage());

                return $payment->refresh();
            }

            $order->update(['payment_method' => PaymentMethod::Wallet->value]);

            return $this->capture($payment->refresh(), $amount);
        });
    }

    private function abandonOpenAttempts(Order $order): void
    {
        Payment::where('order_id', $order->id)->open()->update([
            'status' => PaymentStatus::Failed->value,
            'failed_at' => now(),
            'failure_reason' => 'superseded_by_a_new_attempt',
        ]);
    }
}
