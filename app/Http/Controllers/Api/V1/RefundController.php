<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Payment\Models\Refund;
use App\Modules\Payment\Services\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * «طلب استرداد».
 *
 * A request, not a refund. The design draws «قيد المراجعة» on it, so a person
 * decides and only then does money move.
 */
class RefundController extends Controller
{
    public function __construct(private readonly RefundService $refunds) {}

    public function store(Request $request, $id): JsonResponse
    {
        $order = $request->user()->orders()->find($id);

        if (! $order) {
            return failReturnNotFound(__('Order not found.'));
        }

        $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:191'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        // Omitting the amount asks for everything that is still refundable, which
        // is what a customer usually means.
        $amount = $request->filled('amount')
            ? (float) $request->get('amount')
            : $this->refunds->refundableAmount($order);

        try {
            $refund = $this->refunds->request(
                $order,
                $request->user(),
                $amount,
                $request->get('reason'),
                $request->get('note'),
            );
        } catch (RuntimeException $e) {
            return match ($e->getMessage()) {
                'nothing_to_refund' => failReturnMsg(__('There is nothing to refund on this order.')),
                'exceeds_refundable_amount' => failReturnMsg(__('That is more than can be refunded.')),
                'refund_already_pending' => failReturnMsg(__('A refund request is already under review.')),
                'amount_must_be_positive' => failReturnValidation(
                    ['amount' => [__('Enter an amount greater than zero.')]],
                    __('Enter an amount greater than zero.')
                ),
                default => failReturnMsg(__('We could not submit your request.')),
            };
        }

        return successReturnCreated([
            'id' => $refund->id,
            'amount' => (float) $refund->amount,
            'status' => $refund->status,
            'status_label' => __($refund->statusLabel()),
        ], __('Your refund request has been submitted for review.'));
    }

    public function index(Request $request, $id): JsonResponse
    {
        $order = $request->user()->orders()->find($id);

        if (! $order) {
            return failReturnNotFound(__('Order not found.'));
        }

        $payload = [];

        foreach (Refund::where('order_id', $order->id)->latest('id')->get() as $refund) {
            $payload[] = [
                'id' => $refund->id,
                'amount' => (float) $refund->amount,
                'reason' => $refund->reason,
                'status' => $refund->status,
                'status_label' => __($refund->statusLabel()),
                'destination' => $refund->destination,
                'requested_at' => humanDate($refund->created_at),
                'settled_at' => $refund->settled_at ? humanDate($refund->settled_at) : null,
            ];
        }

        return successReturnData([
            'refundable' => $this->refunds->refundableAmount($order),
            'requests' => $payload,
        ]);
    }
}
