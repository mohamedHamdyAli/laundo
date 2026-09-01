<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\OrderReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The customer's side of the final price.
 *
 * Three actions, matching the three buttons the design puts on «مراجعة السعر
 * النهائي» — confirm, ask for a second count, ask a question — and one read that
 * gives the screen everything it draws.
 *
 * Isolation works as it does everywhere else on the customer API: every lookup
 * starts from `$request->user()->orders()`, so somebody else's order id is a 404
 * rather than a leak.
 */
class OrderReviewController extends Controller
{
    public function __construct(private readonly OrderReviewService $reviews) {}

    /**
     * «مراجعة السعر النهائي» — the comparison screen.
     */
    public function show(Request $request, $id): JsonResponse
    {
        $order = $this->find($request, $id);

        if (! $order) {
            return failReturnNotFound(__('Order not found.'));
        }

        $comparison = $this->reviews->comparison($order);

        return successReturnData($comparison + [
            'code' => $order->code,
            'status' => $order->status->value,
            'status_label' => __($order->status->label()),
            // What the screen is allowed to offer right now.
            'can_confirm' => $order->status->isAwaitingCustomer() && $order->hasFinalPrice(),
            'can_dispute' => $order->status->isAwaitingCustomer(),
            'confirmed_at' => $order->confirmed_at ? humanDate($order->confirmed_at) : null,
            'payment_status' => $order->payment_status,
            'open_queries' => $order->priceQueries()->open()->count(),
        ]);
    }

    /**
     * «تأكيد السعر» — and the work begins.
     */
    public function confirm(Request $request, $id): JsonResponse
    {
        $order = $this->find($request, $id);

        if (! $order) {
            return failReturnNotFound(__('Order not found.'));
        }

        $request->validate([
            'payment_method' => ['nullable', 'in:cash,card,wallet,instapay'],
        ]);

        try {
            $order = $this->reviews->confirm($order, $request->user());
        } catch (RuntimeException $e) {
            return $this->translateFailure($e);
        }

        // Recorded, not charged. P9 wires a gateway; until then a card order is
        // as unpaid as a cash one, and saying otherwise in the data would be a
        // lie the accounting later inherits.
        if ($request->filled('payment_method')) {
            $order->update(['payment_method' => $request->get('payment_method')]);
        }

        return successReturnData([
            'id' => $order->id,
            'status' => $order->status->value,
            'status_label' => __($order->status->label()),
            'final_total' => (float) $order->final_total,
            'payment_status' => $order->payment_status,
            'payment_method' => $order->payment_method,
        ], __('Your order is confirmed and will be prepared now.'));
    }

    /**
     * «طلب مراجعة إضافية».
     */
    public function dispute(Request $request, $id): JsonResponse
    {
        $order = $this->find($request, $id);

        if (! $order) {
            return failReturnNotFound(__('Order not found.'));
        }

        $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        try {
            $order = $this->reviews->dispute($order, $request->user(), $request->get('reason'));
        } catch (RuntimeException $e) {
            return $this->translateFailure($e);
        }

        return successReturnData([
            'id' => $order->id,
            'status' => $order->status->value,
            'status_label' => __($order->status->label()),
            'review_round' => $order->review_round,
        ], __('We will count your pieces again and send you an updated price.'));
    }

    /**
     * «لدي استفسار عن السعر» — the order does not move.
     */
    public function query(Request $request, $id): JsonResponse
    {
        $order = $this->find($request, $id);

        if (! $order) {
            return failReturnNotFound(__('Order not found.'));
        }

        $request->validate(['message' => ['required', 'string', 'max:2000']]);

        $query = $this->reviews->raiseQuery($order, $request->user(), $request->get('message'));

        return successReturnCreated([
            'id' => $query->id,
            'message' => $query->message,
            'answered' => false,
        ], __('Your question has been sent. We will get back to you.'));
    }

    /**
     * The questions this customer has asked about this order, and any answers.
     */
    public function queries(Request $request, $id): JsonResponse
    {
        $order = $this->find($request, $id);

        if (! $order) {
            return failReturnNotFound(__('Order not found.'));
        }

        $payload = [];

        foreach ($order->priceQueries as $query) {
            $payload[] = [
                'id' => $query->id,
                'message' => $query->message,
                'answer' => $query->answer,
                'answered' => $query->isAnswered(),
                'asked_at' => humanDate($query->created_at),
                'answered_at' => $query->answered_at ? humanDate($query->answered_at) : null,
            ];
        }

        return successReturnData($payload);
    }

    private function find(Request $request, $id): ?Order
    {
        return $request->user()->orders()->with(['items.item:id,name', 'service:id,name'])->find($id);
    }

    private function translateFailure(RuntimeException $e): JsonResponse
    {
        return match ($e->getMessage()) {
            'not_awaiting_confirmation' => failReturnMsg(
                __('This order is not waiting for your confirmation.')
            ),
            'no_final_price' => failReturnMsg(__('The final price is not ready yet.')),
            default => failReturnMsg(__('We could not complete that.')),
        };
    }
}
