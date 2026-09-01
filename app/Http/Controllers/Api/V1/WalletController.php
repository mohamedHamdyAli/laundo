<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Payment\Enums\PaymentMethod;
use App\Modules\Wallet\Enums\TransactionReason;
use App\Modules\Wallet\Models\WalletTransaction;
use App\Modules\Wallet\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * «المحفظة».
 *
 * Every route is rooted in the authenticated user's own wallet, so there is no
 * id to guess at — a wallet is reached through its owner and never by key.
 */
class WalletController extends Controller
{
    public function __construct(private readonly WalletService $wallets) {}

    /**
     * «الرصيد الحالي».
     */
    public function show(Request $request): JsonResponse
    {
        $wallet = $this->wallets->forUser($request->user());

        return successReturnData([
            'balance' => (float) $wallet->balance,
            // «الرصيد المعلق» — earned but not yet withdrawable. Zero for a
            // customer, who has no earnings.
            'pending_balance' => (float) $wallet->pending_balance,
            'currency' => $wallet->currency,
            'is_frozen' => $wallet->is_frozen,
        ]);
    }

    /**
     * «سجل المعاملات», with the design's four tabs.
     */
    public function transactions(Request $request): JsonResponse
    {
        $wallet = $this->wallets->forUser($request->user());

        $query = WalletTransaction::where('wallet_id', $wallet->id);

        // الكل / المدفوعات / الإضافات / الاستردادات
        $group = $request->get('group');

        if ($group && $group !== 'all') {
            $reasons = array_filter(
                TransactionReason::cases(),
                fn (TransactionReason $r) => $r->group() === $group
            );

            $query->whereIn('reason', array_map(fn ($r) => $r->value, $reasons));
        }

        $rows = $query->latest('id')->paginate(min((int) $request->get('per_page', 20), 50));

        $payload = [];

        foreach ($rows->items() as $row) {
            $payload[] = [
                'id' => $row->id,
                // «+100 ج.م» / «-150 ج.م» — signed for display, unsigned in store.
                'amount' => $row->signedAmount(),
                'direction' => $row->direction,
                'reason' => $row->reason->value,
                'reason_label' => __($row->reason->label()),
                'note' => $row->note,
                'balance_after' => (float) $row->balance_after,
                'at' => humanDate($row->created_at),
            ];
        }

        return successReturnPaginated($payload, $rows);
    }

    /**
     * «إضافة رصيد».
     *
     * Deliberately does NOT credit anything. A top-up is a payment like any
     * other: money has to arrive through a provider first, and only its webhook
     * may credit the wallet. Crediting here would let anybody with a token print
     * money.
     */
    public function topUp(Request $request): JsonResponse
    {
        $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:100000'],
            'method' => ['required', Rule::in([
                PaymentMethod::Card->value,
                PaymentMethod::InstaPay->value,
            ])],
        ]);

        // The gateway integration for standalone top-ups is not built: P9a wired
        // charging an *order*, and a top-up has no order behind it. Reported
        // honestly rather than silently succeeding.
        return failReturnMsg(
            __('Wallet top-up needs a payment provider, which is not connected yet.')
        );
    }

    /**
     * «سحب الرصيد».
     */
    public function withdraw(Request $request): JsonResponse
    {
        $request->validate(['amount' => ['required', 'numeric', 'min:1']]);

        $amount = (float) $request->get('amount');

        try {
            $transaction = $this->wallets->debit(
                $request->user(),
                $amount,
                TransactionReason::Withdrawal,
                null,
                __('Withdrawal requested')
            );
        } catch (RuntimeException $e) {
            return match ($e->getMessage()) {
                'insufficient_balance' => failReturnMsg(__('Your balance is not enough.')),
                'wallet_frozen' => failReturnForbidden(__('This wallet is on hold.')),
                default => failReturnMsg(__('We could not process the withdrawal.')),
            };
        }

        return successReturnData([
            'amount' => (float) $transaction->amount,
            'balance' => (float) $transaction->balance_after,
        ], __('Withdrawal recorded. It will be paid out by our team.'));
    }
}
