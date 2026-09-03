<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\OrderQuoteRequest;
use App\Http\Requests\Api\V1\OrderRequest;
use App\Modules\Driver\Services\DriverCard;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderMedia;
use App\Modules\Order\Services\OrderService;
use App\Modules\Order\Services\RescheduleService;
use App\Modules\TimeSlot\Models\TimeSlot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The customer's orders.
 *
 * Isolation works exactly as it does for addresses: every lookup starts from
 * `$request->user()->orders()`, so an id belonging to someone else is a 404, not
 * a leak. Note that this is a *customer* route group — the Order model's tenant
 * scope is inactive here, because a customer is not a tenant — which is why the
 * user relation, not the scope, is what does the work.
 */
class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly DriverCard $driverCard,
    ) {}

    /**
     * The design's three tabs: الكل / نشط / مكتمل، plus cancelled.
     */
    public function index(Request $request): JsonResponse
    {
        $tab = $request->get('tab', 'all');

        // `pickupSlot` is here because `presentSummary` reads its label. Without
        // it the summary would fire one slot query per order in the page —
        // fifteen extra round trips to render a list.
        $query = $request->user()->orders()->with([
            'service:id,name', 'laundry:id,name', 'pickupSlot',
        ]);

        $query = match ($tab) {
            'active' => $query->active(),
            'completed' => $query->where('status', OrderStatus::Completed->value),
            'cancelled' => $query->whereIn('status', [
                OrderStatus::Cancelled->value,
                OrderStatus::Returned->value,
            ]),
            default => $query,
        };

        $orders = $query->latest('id')->paginate(min((int) $request->get('per_page', 15), 50));

        return successReturnPaginated(
            array_map(fn (Order $order) => $this->presentSummary($order), $orders->items()),
            $orders
        );
    }

    /**
     * Price preview, before anything is saved.
     */
    public function quote(OrderQuoteRequest $request): JsonResponse
    {
        try {
            $quote = $this->orders->quote($request->user(), $request->validated());
        } catch (RuntimeException $e) {
            return $this->translateFailure($e);
        }

        return successReturnData([
            'items_count' => $quote['items_count'],
            'subtotal' => $quote['subtotal'],
            'delivery_fee' => $quote['delivery_fee'],
            'delivery_distance_km' => $quote['delivery_distance_km'],
            // Non-null when the fee could not be worked out — the app shows «يتم
            // تحديدها لاحقاً» rather than a misleading 0.00.
            'delivery_fee_reason' => $quote['delivery_fee_reason'],
            'discount' => $quote['discount'],
            // The code that actually applied, and — when one was typed and
            // refused — why. A refused code is never fatal: the customer asked
            // for a discount, not for the order to fail.
            'coupon_code' => $quote['coupon_code'] ?? null,
            'coupon_error' => $quote['coupon_error'] ?? null,
            // «قد يتم تطبيق رسوم إضافية» — its own line, never folded into the
            // delivery fee. The customer can remove it by paying another way, and
            // a charge you cannot see is a charge you cannot avoid.
            'cash_surcharge' => $quote['cash_surcharge'],
            'total' => $quote['total'],
            'unpriced_item_ids' => $quote['unpriced'],
            'laundry' => $quote['laundry'],
            'lines' => $quote['lines'],
        ]);
    }

    public function store(OrderRequest $request): JsonResponse
    {
        try {
            $order = $this->orders->place($request->user(), $request->validated());
        } catch (RuntimeException $e) {
            return $this->translateFailure($e);
        }

        // Stain photos, attached after the order exists so they can carry its id.
        foreach ((array) $request->file('photos', []) as $photo) {
            $path = uploadOrUpdateImage($photo, 'images/orders/stains');

            if ($path) {
                OrderMedia::create([
                    'order_id' => $order->id,
                    'type' => 'stain',
                    'path' => $path,
                    'uploaded_by' => $request->user()->id,
                ]);
            }
        }

        return successReturnCreated(
            $this->presentDetail($this->find($request, $order->id)),
            __('Your order has been placed.')
        );
    }

    public function show(Request $request, $id): JsonResponse
    {
        $order = $this->find($request, $id);

        if (! $order) {
            return failReturnNotFound(__('Order not found.'));
        }

        return successReturnData($this->presentDetail($order));
    }

    /**
     * The tracking screen: the five-point timeline plus the log behind it.
     */
    public function track(Request $request, $id): JsonResponse
    {
        $order = $request->user()->orders()->with('statusLogs')->find($id);

        if (! $order) {
            return failReturnNotFound(__('Order not found.'));
        }

        $reached = $order->statusLogs->pluck('to_status')->all();
        $steps = [];

        foreach (OrderStatus::trackingSteps() as $step) {
            $log = $order->statusLogs->firstWhere('to_status', $step->value);

            $steps[] = [
                'status' => $step->value,
                'label' => __($step->label()),
                'reached' => in_array($step->value, $reached, true),
                'at' => $log ? humanDate($log->created_at) : null,
            ];
        }

        return successReturnData([
            'code' => $order->code,
            'status' => $order->status->value,
            'status_label' => __($order->status->label()),
            'is_active' => $order->status->isActive(),
            'can_cancel' => $order->status->isCancellable(),
            // «مندوب الاستلام · أحمد · ★ 4.9». Null between journeys and before
            // anybody is assigned, which the design already draws as an empty
            // card rather than a missing one.
            'driver' => $this->driverCard->forOrder($order),
            'steps' => $steps,
        ]);
    }

    public function cancel(Request $request, $id): JsonResponse
    {
        $order = $this->find($request, $id);

        if (! $order) {
            return failReturnNotFound(__('Order not found.'));
        }

        try {
            $order = $this->orders->cancel($order, $request->user(), $request->get('reason'));
        } catch (RuntimeException $e) {
            return $this->translateFailure($e);
        }

        return successReturnData([
            'id' => $order->id,
            'status' => $order->status->value,
            'status_label' => __($order->status->label()),
        ], __('Your order has been cancelled.'));
    }

    /**
     * «إعادة الطلب» — hands the app a pre-filled basket rather than creating an
     * order outright. The customer still confirms the schedule and sees the
     * current price, which may have moved since the original order.
     */
    public function reorder(Request $request, $id): JsonResponse
    {
        $order = $this->find($request, $id);

        if (! $order) {
            return failReturnNotFound(__('Order not found.'));
        }

        return successReturnData($this->orders->reorderPayload($order));
    }

    private function find(Request $request, $id): ?Order
    {
        return $request->user()->orders()->with([
            'service:id,name', 'laundry:id,name',
            'pickupAddress', 'deliveryAddress', 'pickupSlot', 'deliverySlot',
            'items.item:id,name', 'media',
        ])->find($id);
    }

    /**
     * Turn the service layer's failure codes into the customer's message.
     *
     * The service throws codes rather than sentences on purpose: it has no
     * business knowing about HTTP status or the request locale.
     */
    private function translateFailure(RuntimeException $e): JsonResponse
    {
        $message = $e->getMessage();

        if (str_starts_with($message, 'unpriced_items:')) {
            return failReturnValidation(
                ['items' => [__('Some pieces are not available for this service.')]],
                __('Some pieces are not available for this service.')
            );
        }

        return match ($message) {
            'service_not_found' => failReturnNotFound(__('Service not found.')),
            'pickup_address_not_found' => failReturnNotFound(__('Pickup address not found.')),
            'delivery_address_not_found' => failReturnNotFound(__('Delivery address not found.')),
            'empty_basket' => failReturnValidation(
                ['items' => [__('Please add at least one piece.')]],
                __('Please add at least one piece.')
            ),
            'not_cancellable' => failReturnMsg(
                __('This order can no longer be cancelled.')
            ),
            // Named against the field so the wizard can mark the window red
            // rather than showing a banner over the whole form.
            'slot_full' => failReturnValidation(
                ['pickup_slot_id' => [__('This window is fully booked. Please choose another one.')]],
                __('This window is fully booked. Please choose another one.')
            ),
            default => failReturnMsg(__('We could not complete your order.')),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function presentSummary(Order $order): array
    {
        return [
            'id' => $order->id,
            'code' => $order->code,
            'status' => $order->status->value,
            'status_label' => __($order->status->label()),
            'is_active' => $order->status->isActive(),
            'can_cancel' => $order->status->isCancellable(),
            'service' => $order->service ? getLocalizedValue($order->service, 'name') : null,
            'laundry' => $order->laundry ? getLocalizedValue($order->laundry, 'name') : null,
            'items_count' => $order->final_items_count ?? $order->estimated_items_count,
            'total' => $order->payableTotal(),
            'pickup_date' => $order->pickup_date?->toDateString(),
            // Both of these were detail-only, which meant the home screen's
            // «طلبك الحالي» card — a *list* row — could not draw the pickup
            // time or its «مسح QR» button without a second request per order.
            // The window rather than a single time: a driver on a route cannot
            // promise a minute, which is why slots are modelled as ranges.
            'pickup_slot' => $order->pickupSlot?->label(),
            // «إظهار رمز الاستلام (QR)» — the code the driver scans to confirm
            // they are at the right parcel. It is the customer's own order, and
            // the button appears on three screens including this card.
            'qr' => $order->qr_token,
            'created_at' => humanDate($order->created_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDetail(Order $order): array
    {
        $items = [];

        foreach ($order->items as $line) {
            $items[] = [
                'item_id' => $line->item_id,
                'name' => $line->item ? getLocalizedValue($line->item, 'name') : null,
                'phase' => $line->phase,
                'qty' => $line->qty,
                'unit_price' => (float) $line->unit_price,
                'line_total' => (float) $line->line_total,
            ];
        }

        $photos = [];

        foreach ($order->media as $medium) {
            $photos[] = ['type' => $medium->type, 'url' => $medium->url()];
        }

        return $this->presentSummary($order) + [
            // Both legs. Raw `door`/`leave` — the app maps them, as it always
            // has for this field.
            'pickup_method' => $order->pickup_method,
            'delivery_method' => $order->delivery_method,
            'driver_note' => $order->driver_note,
            'special_instructions' => $order->special_instructions,
            'pickup_address_id' => $order->pickup_address_id,
            'delivery_address_id' => $order->delivery_address_id,
            'same_address' => $order->isRoundTrip(),
            // `pickup_slot` and `qr` moved up into the summary, which this
            // composes with `+` — repeating them here would be dead keys.
            'delivery_slot' => $order->deliverySlot?->label(),
            'delivery_date' => $order->delivery_date?->toDateString(),
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status,
            'pricing' => [
                'estimated_subtotal' => (float) $order->estimated_subtotal,
                'delivery_fee' => (float) $order->delivery_fee,
                'discount' => (float) $order->discount_total,
                // Its own line here as well as in the quote. A total carrying a
                // fee that appears nowhere is a fee the customer cannot check.
                'cash_surcharge' => (float) $order->cash_surcharge,
                'estimated_total' => (float) $order->estimated_total,
                // Null until the laundry has counted the pieces in P7.
                'final_subtotal' => $order->final_subtotal !== null ? (float) $order->final_subtotal : null,
                'final_total' => $order->final_total !== null ? (float) $order->final_total : null,
            ],
            'items' => $items,
            'photos' => $photos,
        ];
    }

    /**
     * «اختيار موعد جديد» — after a postponement.
     *
     * A driver recording «طلب التأجيل» used to send the journey straight back to
     * the queue, so the next driver was offered the same trip within seconds after
     * the customer had just said "not now". Now the leg stops and waits for this.
     *
     * The GET half matters as much as the POST: the app has to know whether to
     * show the prompt, and which end of the order it is about, without inferring
     * either from a status.
     */
    public function rescheduleOptions(Request $request, $id): JsonResponse
    {
        $order = $request->user()->orders()->find($id);

        if (! $order) {
            return failReturnNotFound(__('Order not found.'));
        }

        $service = app(RescheduleService::class);
        $task = $service->postponedTask($order);

        if ($task === null) {
            return successReturnData([
                'needs_new_time' => false,
                'leg' => null,
                'slots' => [],
            ]);
        }

        $collection = in_array($task->type, [
            TaskType::PickupFromCustomer,
            TaskType::DeliverToLaundry,
        ], true);

        // Only the slots this leg may actually use. Offering a delivery-only slot
        // for a collection would be a choice the server then refuses.
        $slots = TimeSlot::where('status', 'active')
            ->whereIn('applies_to', ['both', $collection ? 'pickup' : 'delivery'])
            ->orderBy('sort_order')
            ->orderBy('start_time')
            ->get(['id', 'start_time', 'end_time']);

        return successReturnData([
            'needs_new_time' => true,
            'leg' => $collection ? 'pickup' : 'delivery',
            'slots' => $slots->map(fn (TimeSlot $slot) => [
                'id' => $slot->id,
                'from' => $slot->start_time,
                'to' => $slot->end_time,
            ])->values(),
        ]);
    }

    public function reschedule(Request $request, $id): JsonResponse
    {
        $order = $request->user()->orders()->find($id);

        if (! $order) {
            return failReturnNotFound(__('Order not found.'));
        }

        $validated = $request->validate([
            'slot_id' => ['required', 'integer'],
            // Today counts: a customer postponed at nine in the morning may well
            // want the afternoon.
            'date' => ['required', 'date', 'after_or_equal:today'],
        ]);

        try {
            $order = app(RescheduleService::class)->reschedule($order, $request->user(), $validated);
        } catch (RuntimeException $e) {
            return match ($e->getMessage()) {
                'nothing_to_reschedule' => failReturnMsg(__('This order is not waiting for a new time.')),
                'slot_not_available' => failReturnMsg(__('That time is not available.')),
                'slot_full' => failReturnMsg(__('This window is fully booked. Please choose another one.')),
                'date_in_the_past' => failReturnMsg(__('Choose a date from today onwards.')),
                'not_your_order' => failReturnNotFound(__('Order not found.')),
                default => failReturnMsg(__('Could not set a new time.')),
            };
        }

        return successReturnData(
            ['id' => $order->id, 'code' => $order->code],
            __('Your new time is set. We will collect it then.')
        );
    }
}
