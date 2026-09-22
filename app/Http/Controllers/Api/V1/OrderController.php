<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\OrderQuoteRequest;
use App\Http\Requests\Api\V1\OrderRequest;
use App\Modules\Address\Models\Address;
use App\Modules\Driver\Services\DriverCard;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderMedia;
use App\Modules\Order\Models\RecurrencePrompt;
use App\Modules\Order\Services\OrderEta;
use App\Modules\Order\Services\OrderService;
use App\Modules\Order\Services\OrderTimeline;
use App\Modules\Order\Services\RecurrenceService;
use App\Modules\Order\Services\RescheduleService;
use App\Modules\TimeSlot\Models\TimeSlot;
use App\Modules\TimeSlot\Services\SlotCapacity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
            // «ضريبة الدولة». The rate as well as the amount, so the app can
            // label the line «ضريبة 10%» rather than an unexplained number, and
            // `pre_tax_total` so the two halves are checkable against the total
            // without the client doing the subtraction itself.
            'pre_tax_total' => $quote['pre_tax_total'],
            'tax_rate' => $quote['tax_rate'],
            'tax' => $quote['tax'],
            'total' => $quote['total'],
            'unpriced_item_ids' => $quote['unpriced'],
            'laundry' => $quote['laundry'],
            'lines' => $quote['lines'],
        ]);
    }

    public function store(OrderRequest $request): JsonResponse
    {
        $data = $request->validated();
        $promptId = $data['prompt_id'] ?? null;
        unset($data['prompt_id']);

        $prompt = $promptId ? $this->findPrompt($request, $promptId) : null;

        // Validated as existing, but existing is not the same as the caller's.
        if ($promptId && ! $prompt) {
            return failReturnNotFound(__('Request not found.'));
        }

        try {
            // Answering a repeat schedule's question and placing an ordinary
            // order are the same write; the prompt only adds what it has to be
            // closed with, so the wizard's own data stays authoritative.
            $order = $prompt
                ? app(RecurrenceService::class)->placeFromPrompt($prompt, $request->user(), $data)
                : $this->orders->place($request->user(), $data);
        } catch (RuntimeException $e) {
            return $e->getMessage() === 'already_answered'
                ? failReturnMsg(__('You have already answered this request.'))
                : $this->translateFailure($e);
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
        // `tasks` as well as the logs: the timeline's «on the way to you» step is
        // read off the third leg, and without this it is a query per request.
        $order = $request->user()->orders()
            // The two addresses are for the map pins below; without them this is
            // two extra queries on a screen that polls. The two slots are the
            // ETA's window — same reason, same screen.
            ->with(['statusLogs', 'tasks', 'pickupAddress', 'deliveryAddress', 'pickupSlot', 'deliverySlot'])
            ->find($id);

        if (! $order) {
            return failReturnNotFound(__('Order not found.'));
        }

        $eta = app(OrderEta::class)->forOrder($order);

        return successReturnData([
            'code' => $order->code,
            'status' => $order->status->value,
            'status_label' => __($order->status->label()),
            'is_active' => $order->status->isActive(),
            'can_cancel' => $order->status->isCancellable(),
            // How the clothes are handed over. **`door` / `leave`, and those are
            // the only two** — there are no collection points, lockers or
            // branches anywhere in this system, so a vocabulary naming them
            // would describe a service that does not exist.
            'delivery_method' => $order->delivery_method,
            'delivery_method_label' => __($this->deliveryMethodLabel($order->delivery_method)),
            // The booked window for the leg on its way, not a routed arrival —
            // see OrderEta. Null when nothing is currently coming.
            'eta_iso' => $eta['iso'] ?? null,
            'eta_minutes' => $eta['minutes'] ?? null,
            'eta_label' => $eta['label'] ?? null,
            'eta_window' => $eta['window'] ?? null,
            'eta_source' => $eta['source'] ?? null,
            // «مندوب الاستلام · أحمد · ★ 4.9». Null between journeys and before
            // anybody is assigned, which the design already draws as an empty
            // card rather than a missing one.
            'driver' => $this->driverCard->forOrder($order),
            // Where the two handovers happen. The screen already draws a map for
            // the driver's dot, and it had nothing to anchor that dot against —
            // a moving marker on an empty map does not tell a customer whether
            // the van is near their street. `driver.location` is the driver;
            // these two are the doors.
            'pickup_location' => $this->point($order->pickupAddress),
            'delivery_location' => $this->point($order->deliveryAddress),
            // The same two doors as something a person can read. The coordinates
            // above place a pin; they do not tell a customer *where* their
            // clothes are going, and the screen names the destination.
            'pickup_address' => $this->addressCard($order->pickupAddress),
            'delivery_address' => $this->addressCard($order->deliveryAddress),
            // Eight steps, not the landing page's six. `OrderStatus::trackingSteps()`
            // is the marketing journey and stays that; a customer waiting at home
            // needs the two «on the way» states it leaves out. See OrderTimeline.
            'steps' => app(OrderTimeline::class)->for($order),
        ]);
    }

    /**
     * «فين المندوب دلوقتي» — the polling endpoint behind the moving marker.
     *
     * Separate from `track()` because the two are asked at completely different
     * rates. The tracking screen is fetched when it opens and when something
     * changes; the dot is fetched every few seconds for as long as somebody is
     * watching it. Serving the timeline, both addresses, the ETA and the eight
     * steps on every one of those is the wrong trade — this fetches the one leg
     * somebody is on and that driver's stored position, and answers in a few
     * dozen bytes.
     *
     * Scoped through the customer's own orders, like everything else here, so
     * somebody else's order id is a 404 rather than a stranger's driver on a map.
     */
    public function driverLocation(Request $request, $id): JsonResponse
    {
        // No eager loads: `DriverCard` loads the legs and their driver itself,
        // and nothing else on the order is read.
        $order = $request->user()->orders()->find($id);

        if (! $order) {
            return failReturnNotFound(__('Order not found.'));
        }

        return successReturnData($this->driverCard->trackingFor($order));
    }

    /**
     * An address as a map point, or null when nobody placed the pin.
     *
     * Null rather than zeroes: (0, 0) is a spot in the Atlantic and an app that
     * trusts it draws a marker a thousand miles away instead of drawing none.
     *
     * @return array{lat: float, lng: float}|null
     */
    private function point(?Address $address): ?array
    {
        // `addresses.lat` and `.lng` are NOT NULL — the pin is taken when the
        // address is saved — so the only way there is no point is no address.
        if ($address === null) {
            return null;
        }

        return ['lat' => (float) $address->lat, 'lng' => (float) $address->lng];
    }

    /**
     * An address as the tracking screen names it.
     *
     * @return array<string, mixed>|null
     */
    private function addressCard(?Address $address): ?array
    {
        if ($address === null) {
            return null;
        }

        return [
            'id' => $address->id,
            'label' => $address->label,
            'line' => $address->street,
            'lat' => (float) $address->lat,
            'lng' => (float) $address->lng,
        ];
    }

    /**
     * «يتسلّم باليد» / «يتساب عند الباب».
     *
     * Two values, because the service has two. Untranslated here and run through
     * `__()` by the caller, in the request's own language.
     */
    private function deliveryMethodLabel(?string $method): string
    {
        // Both keys are already carried and translated — the panel names the
        // same two choices on the order screen. Inventing new ones would ship
        // two untranslated strings to the app for no gain.
        return match ($method) {
            'leave' => 'Leave at the door',
            default => 'Hand to the customer',
        };
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

    /**
     * A repeat schedule's question, if it belongs to the caller.
     *
     * Reached through the schedule's owner rather than the prompt itself, which
     * has no user of its own.
     */
    private function findPrompt(Request $request, $id): ?RecurrencePrompt
    {
        return RecurrencePrompt::whereHas(
            'recurrence',
            fn ($q) => $q->where('user_id', $request->user()->id)
        )->find($id);
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
            'created_at_iso' => isoDate($order->created_at),
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
                // The order's OWN rate, not the setting's. An order placed at
                // 10% keeps 10% when the rate moves, so an app re-opening an old
                // order shows the tax that was actually charged.
                'tax_rate' => $order->taxRate(),
                'estimated_tax' => (float) $order->estimated_tax,
                'estimated_total' => (float) $order->estimated_total,
                // Null until the laundry has counted the pieces in P7.
                'final_subtotal' => $order->final_subtotal !== null ? (float) $order->final_subtotal : null,
                'final_tax' => $order->final_tax !== null ? (float) $order->final_tax : null,
                'final_total' => $order->final_total !== null ? (float) $order->final_total : null,
                // The pair that actually applies, so a client rendering a bill
                // does not have to know whether the pieces have been counted yet.
                'payable_tax' => $order->payableTax(),
                'payable_total' => $order->payableTotal(),
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
                'date' => null,
                'current_date' => null,
                'slot_id' => null,
                'current_slot_id' => null,
                'slots' => [],
            ]);
        }

        $collection = in_array($task->type, [
            TaskType::PickupFromCustomer,
            TaskType::DeliverToLaundry,
        ], true);

        // Which day the capacity figures are about. The postponed leg has had its
        // own date cleared, so «today» is the honest default — and a client
        // walking a date picker passes the day it is showing.
        $date = $request->filled('date')
            ? Carbon::parse($request->get('date'))
            : ($collection ? $order->pickup_date : $order->delivery_date) ?? now();

        // Only the slots this leg may actually use. Offering a delivery-only slot
        // for a collection would be a choice the server then refuses.
        $slots = TimeSlot::where('status', 'active')
            ->whereIn('applies_to', ['both', $collection ? 'pickup' : 'delivery'])
            ->orderBy('sort_order')
            ->orderBy('start_time')
            ->get();

        $capacity = app(SlotCapacity::class);

        return successReturnData([
            'needs_new_time' => true,
            'leg' => $collection ? 'pickup' : 'delivery',
            // The day the rows below are counted against, and the booking this
            // is replacing. Both under two names, because the app reads one and
            // this endpoint has always been shaped like the other.
            'date' => $date->toDateString(),
            'current_date' => ($collection ? $order->pickup_date : $order->delivery_date)?->toDateString(),
            'slot_id' => $collection ? $order->pickup_slot_id : $order->delivery_slot_id,
            'current_slot_id' => $collection ? $order->pickup_slot_id : $order->delivery_slot_id,
            // The same shape `GET /time-slots` sends. It used to be three keys,
            // so the app built the label itself and could not tell a full day
            // from an unknown one — `remaining` is null for an uncapped window
            // and 0 for a full one, and those are different answers.
            'slots' => $slots->map(function (TimeSlot $slot) use ($capacity, $date) {
                $remaining = $capacity->remaining($slot, $date);

                return [
                    'id' => $slot->id,
                    'from' => $slot->start_time,
                    'to' => $slot->end_time,
                    'label' => $slot->label(),
                    'applies_to' => $slot->applies_to,
                    'capacity' => $slot->capacity,
                    'remaining' => $remaining,
                    'is_full' => $remaining !== null && $remaining < 1,
                ];
            })->values(),
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

        $service = app(RescheduleService::class);

        // Which end is being rebooked, read **before** the write: once the leg
        // is pending again `postponedTask()` finds nothing, and deducing the leg
        // afterwards from which columns moved is a guess that goes wrong the
        // moment both ends carry the same slot and date.
        $task = $service->postponedTask($order);

        $collection = $task !== null && in_array($task->type, [
            TaskType::PickupFromCustomer,
            TaskType::DeliverToLaundry,
        ], true);

        try {
            $order = $service->reschedule($order, $request->user(), $validated);
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

        $slot = $collection ? $order->pickupSlot : $order->deliverySlot;

        // **The same order, the same code.** It is a re-booking, not a new
        // order, and the response says so in the fields rather than leaving the
        // app to re-fetch and find out. Returning the booked result also closes
        // the round-trip the screen used to need to redraw itself.
        return successReturnData([
            'id' => $order->id,
            'code' => $order->code,
            'leg' => $collection ? 'pickup' : 'delivery',
            'date' => ($collection ? $order->pickup_date : $order->delivery_date)?->toDateString(),
            'time_slot' => $slot ? [
                'id' => $slot->id,
                'from' => $slot->start_time,
                'to' => $slot->end_time,
                'label' => $slot->label(),
            ] : null,
            // False by construction — the leg this endpoint was waiting on is
            // pending again. Asked rather than hardcoded, so an order carrying a
            // second postponed leg still says so.
            'needs_new_time' => $service->isAwaitingNewSlot($order),
            'status' => $order->status->value,
            'status_label' => __($order->status->label()),
        ], __('Your new time is set. We will collect it then.'));
    }
}
