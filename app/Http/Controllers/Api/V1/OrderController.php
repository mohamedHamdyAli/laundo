<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\AnswersOutOfCoverage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\OrderQuoteRequest;
use App\Http\Requests\Api\V1\OrderRequest;
use App\Modules\Address\Models\Address;
use App\Modules\Driver\Services\DriverCard;
use App\Modules\Loyalty\Services\OrderPoints;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\RecurrencePrompt;
use App\Modules\Order\Services\OrderEta;
use App\Modules\Order\Services\OrderPieces;
use App\Modules\Order\Services\OrderService;
use App\Modules\Order\Services\OrderTimeline;
use App\Modules\Order\Services\OutOfCoverage;
use App\Modules\Order\Services\PieceLimits;
use App\Modules\Order\Services\PlacementFailure;
use App\Modules\Order\Services\RecurrenceService;
use App\Modules\Order\Services\RescheduleService;
use App\Modules\Order\Services\Turnaround;
use App\Modules\Payment\Enums\PaymentMethod;
use App\Modules\TimeSlot\Models\TimeSlot;
use App\Modules\TimeSlot\Services\SlotCapacity;
use App\Modules\TimeSlot\Services\SlotClock;
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
    use AnswersOutOfCoverage;

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
        ])->withExists($this->complaintFlag($request))->withSum(...$this->paidSum());

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
        } catch (OutOfCoverage $e) {
            return $this->outOfCoverage($request, $e);
        } catch (RuntimeException $e) {
            return $this->translateFailure($e);
        }

        return successReturnData([
            'items_count' => $quote['items_count'],
            'subtotal' => $quote['subtotal'],
            'delivery_fee' => $quote['delivery_fee'],
            // «رسوم المشوار» (2026-11-08): the two trips `delivery_fee` is made
            // of — 0 for a visit another of the customer's orders already pays.
            // A cash order pays `pickup_fee` to the driver at the pickup.
            'pickup_fee' => $quote['pickup_fee'],
            'return_fee' => $quote['return_fee'],
            'delivery_distance_km' => $quote['delivery_distance_km'],
            // Non-null when the fee could not be worked out — the app shows «يتم
            // تحديدها لاحقاً» rather than a misleading 0.00.
            'delivery_fee_reason' => $quote['delivery_fee_reason'],
            'discount' => $quote['discount'],
            // «النقاط» — what the customer holds, what the order would let them
            // spend (for the toggle's label, whatever it is set to), what they
            // are spending, and what completing it will earn. `discount` above
            // is the coupon's; this is its own line.
            'points' => [
                'available' => $quote['points_available'],
                'redeemable' => $quote['points_redeemable'],
                'redeemable_discount' => $quote['points_redeemable_discount'],
                'used' => $quote['points_used'],
                'discount' => $quote['points_discount'],
                'to_earn' => $quote['points_to_earn'],
            ],
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

        // Nobody's, or somebody else's: one answer, so the id says nothing.
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
        } catch (OutOfCoverage $e) {
            return $this->outOfCoverage($request, $e);
        } catch (RuntimeException $e) {
            return $e->getMessage() === 'already_answered'
                ? failReturnMsg(__('You have already answered this request.'))
                : $this->translateFailure($e);
        }

        // Stain photos, attached after the order exists so they can carry its id.
        $this->orders->attachStainPhotos($order, (array) $request->file('photos', []), $request->user());

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
            // Nine steps, not the landing page's six («خلص الغسيل» since 2026-11-08). `OrderStatus::trackingSteps()`
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
        ])->withExists($this->complaintFlag($request))->withSum(...$this->paidSum())->find($id);
    }

    /**
     * What has been taken for each order (`Order::paidSoFar()`), summed in the
     * list's own query — `still_owed` is on every card, and asking per order
     * would be a query per card. Read back by `paid()`.
     *
     * @return array{0: array<string, \Closure>, 1: string}
     */
    private function paidSum(): array
    {
        return [['payments as captured_amount' => fn ($query) => $query->captured()], 'amount'];
    }

    /**
     * What has been paid for this order so far: the sum `paidSum()` loaded,
     * else asked (`Order::paidSoFar()`). The sum is null for an order with no
     * captured payment, which is why presence is tested on the key.
     */
    private function paid(Order $order): float
    {
        return array_key_exists('captured_amount', $order->getAttributes())
            ? round((float) $order->getAttribute('captured_amount'), 2)
            : $order->paidSoFar();
    }

    /**
     * The bill as the customer is shown it: the counted price once it has been
     * sent to them, the estimate until then — an unsent draft count (item 12)
     * leaves the estimate standing.
     */
    private function shownTotal(Order $order): float
    {
        return $order->customerSeesFinal() ? $order->payableTotal() : (float) $order->estimated_total;
    }

    /**
     * «المتبقي» — what the customer still has to pay: the bill they are shown,
     * less what has been taken. A cash order pays its pickup trip's fee at the
     * pickup, and the app showed the whole bill as still to pay afterwards while
     * the driver was told to take the rest (2026-11-11).
     *
     * `Order::stillOwed()` — the driver's figure — measures against
     * `payableTotal()`, which counts a draft the customer has not been sent;
     * this measures against what the customer is shown. The two differ only
     * while a count is unsent, and nothing is collected then. Nothing on a
     * cancelled order, which owes nothing, nor on a refunded one.
     */
    private function stillOwed(Order $order): float
    {
        if (in_array($order->payment_status, ['paid', 'refunded'], true) || $order->status === OrderStatus::Cancelled) {
            return 0.0;
        }

        return round(max(0.0, $this->shownTotal($order) - $this->paid($order)), 2);
    }

    /**
     * What the driver takes at the pickup: a cash order's pickup trip fee, no
     * more than is owed — the rule `OrderTask::toCollect()` gives the driver —
     * and only while the pickup is still ahead (`AwaitingPickup`/`DriverOnWay`;
     * completing the pickup moves the order on, and a cancelled order never
     * gets there). It said the trip fee for good, so the customer was still
     * told «هتدفع 25 للمندوب وقت الاستلام» after paying it (2026-11-11). A
     * pickup that took nothing leaves the fee to the delivery, where
     * `still_owed` already counts it.
     *
     * Read off the order's status, not its pickup leg: a postponed pickup
     * leaves its leg `failed`, and stopping an order does not close a failed
     * leg, so the leg alone said 25 on a cancelled order.
     */
    private function payAtPickup(Order $order): float
    {
        $ahead = in_array($order->status, [OrderStatus::AwaitingPickup, OrderStatus::DriverOnWay], true);

        if (! $ahead || $order->payment_method !== PaymentMethod::Cash->value || (float) $order->pickup_fee <= 0) {
            return 0.0;
        }

        return round(min((float) $order->pickup_fee, $this->stillOwed($order)), 2);
    }

    /**
     * `has_complaint`, asked in the same query as the orders rather than once
     * per card. The caller's own complaints only, by the rule the refusal uses
     * (`Complaint::scopeUsingUpTheOrder()`), so a driver's complaint about the
     * same delivery does not grey out the customer's button.
     *
     * @return array<string, \Closure>
     */
    private function complaintFlag(Request $request): array
    {
        $customerId = (int) $request->user()->id;

        return ['complaints as has_complaint' => fn ($query) => $query->usingUpTheOrder($customerId)];
    }

    /**
     * Turn the service layer's failure codes into the customer's message.
     *
     * What each code means is `PlacementFailure`, shared with the panel's
     * «إنشاء طلب»; this decides only the envelope. An id that is not the
     * caller's answers 404, so it says nothing about whose it is.
     */
    private function translateFailure(RuntimeException $e): JsonResponse
    {
        $failure = PlacementFailure::describe($e);

        return match ($failure['kind']) {
            'validation' => failReturnValidation([$failure['field'] => [$failure['message']]], $failure['message']),
            'not_found' => failReturnNotFound($failure['message']),
            default => failReturnMsg($failure['message']),
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
            // Always a string: the apps parse it as one, and `null` for an order
            // no laundry has been set on broke `GET /orders` for that customer
            // (2026-11-01). `laundry_assigned` says which of the two it is.
            'laundry' => $order->laundry ? getLocalizedValue($order->laundry, 'name') : __('Not assigned yet'),
            'laundry_assigned' => $order->laundry !== null,
            // The same pieces `items` lists (`Order::customerSeesFinal()`).
            'items_count' => $order->customerSeesFinal() ? $order->final_items_count : $order->estimated_items_count,
            // The bill as the customer is shown it — an unsent draft count
            // (item 12) leaves the estimate standing.
            'total' => $this->shownTotal($order),
            // «المتبقي»: `total` less what has been paid — the pickup's trip fee
            // on a cash order (2026-11-11).
            'still_owed' => $this->stillOwed($order),
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
            // «شكوى» on the card: greyed out once the customer has sent one
            // about this order — one per order, and the server refuses a second
            // (`ComplaintService::hasComplained()`). Always a bool: both queries
            // that reach here load it with `complaintFlag()`.
            'has_complaint' => (bool) $order->getAttribute('has_complaint'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDetail(Order $order): array
    {
        $pieces = app(OrderPieces::class);

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
                // «رسوم المشوار»: the two trips it is made of — null on an order
                // priced by distance before 2026-11-08.
                'pickup_fee' => $order->pickup_fee !== null ? (float) $order->pickup_fee : null,
                'return_fee' => $order->return_fee !== null ? (float) $order->return_fee : null,
                // What the driver takes at the pickup (a cash order's pickup
                // trip) — 0 once the pickup is done — what has been paid so far,
                // and what is left: `payable_total` less `paid_so_far`, the
                // figure the driver collects at the delivery (2026-11-11).
                'pay_at_pickup' => $this->payAtPickup($order),
                'paid_so_far' => $this->paid($order),
                'still_owed' => $this->stillOwed($order),
                'discount' => (float) $order->discount_total,
                // «خصم النقاط» — after a review, what is left of it: fewer
                // pieces counted hands points back.
                'points_used' => (int) $order->points_used,
                'points_discount' => (float) $order->points_discount,
                // Its own line here as well as in the quote. A total carrying a
                // fee that appears nowhere is a fee the customer cannot check.
                'cash_surcharge' => (float) $order->cash_surcharge,
                // The order's OWN rate, not the setting's. An order placed at
                // 10% keeps 10% when the rate moves, so an app re-opening an old
                // order shows the tax that was actually charged.
                'tax_rate' => $order->taxRate(),
                'estimated_tax' => (float) $order->estimated_tax,
                'estimated_total' => (float) $order->estimated_total,
                // Null until the laundry's count has been SENT to the customer
                // (the client's sheet, item 12, 2026-11-08) — a draft the
                // platform has not sent yet is not the customer's to see.
                'final_subtotal' => $order->customerSeesFinal() ? (float) $order->final_subtotal : null,
                'final_tax' => $order->customerSeesFinal() ? (float) $order->final_tax : null,
                'final_total' => $order->customerSeesFinal() ? (float) $order->final_total : null,
                // The pair that actually applies, so a client rendering a bill
                // does not have to know whether the pieces have been counted yet.
                'payable_tax' => $order->customerSeesFinal() ? $order->payableTax() : (float) $order->estimated_tax,
                'payable_total' => $this->shownTotal($order),
            ],
            // The pieces that ARE the order now: the laundry's count once the
            // customer is shown it, their basket before — never both in one
            // list, which read as a count that matched neither (the client's
            // sheet, item 2). `change` says what the count did to each line.
            'items' => $pieces->current($order),
            // The basket as the customer placed it, each line with what was
            // found (`counted`, `change: missing` for a piece not received).
            'estimated_items' => $pieces->estimated($order),
            // `pieces_added` / `pieces_missing`.
            ...$pieces->totals($order),
            'photos' => $photos,
            // What this order pays in points once it completes, and what it
            // has paid so far — the order's own and its hangers.
            'points' => [
                // Zero once the count has fallen under the minimum that earns.
                'to_earn' => app(OrderPoints::class)->promised($order),
                'earned' => app(OrderPoints::class)->earnedOn($order),
                // Asked rather than eager-loaded: `find()` also serves cancel,
                // which walks the legs, and half-loaded legs there would be a
                // trap. The driver's count, the laundry's, and what still
                // waits for the laundry — hanger points are paid on its count
                // (item 11, 2026-11-08).
                ...$this->hangers($order),
            ],
        ];
    }

    /**
     * The hangers handed back on this order's legs (the client's sheet, item
     * 11, 2026-11-08): what the driver recorded, what the laundry confirmed —
     * the points follow that — and what is still waiting for the laundry.
     * One query, on the detail only.
     *
     * **`reorder()` is load-bearing.** `tasks()` is defined with
     * `orderBy('sequence')`, and `sum()` would have dropped it, but a
     * `selectRaw` aggregate keeps it — and MariaDB under ONLY_FULL_GROUP_BY
     * (Laravel's strict mode) refuses an ORDER BY column in an aggregate with no
     * GROUP BY (error 1140). SQLite and MySQL 8 accept it, so the suite stayed
     * green while every order screen in the customer app answered 500
     * (2026-11-11).
     *
     * @return array{hangers_returned: int, hangers_confirmed: int, hangers_awaiting_laundry: int}
     */
    private function hangers(Order $order): array
    {
        $row = $order->tasks()->reorder()->toBase()->selectRaw(
            'coalesce(sum(hangers_returned), 0) as returned, '
            .'coalesce(sum(case when hangers_confirmed_at is not null then hangers_confirmed else 0 end), 0) as confirmed, '
            .'coalesce(sum(case when hangers_confirmed_at is null and status = ? then hangers_returned else 0 end), 0) as waiting',
            [TaskStatus::Completed->value],
        )->first();

        return [
            'hangers_returned' => (int) ($row->returned ?? 0),
            'hangers_confirmed' => (int) ($row->confirmed ?? 0),
            'hangers_awaiting_laundry' => (int) ($row->waiting ?? 0),
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

        $collection = $task->type->onPickupWindow();

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
        $clock = app(SlotClock::class);

        // For a delivery being rebooked: the earliest the service lets it come
        // back, so a window too soon after the pickup is marked, not offered and
        // then refused.
        $turnaround = app(Turnaround::class);
        $earliest = ! $collection && $order->service
            ? $turnaround->earliestDelivery($order->service, $order->pickup_date, $order->pickupSlot)
            : null;

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
            'slots' => $slots->map(function (TimeSlot $slot) use ($capacity, $date, $earliest, $turnaround, $clock, $collection, $order) {
                $remaining = $capacity->remaining($slot, $date);
                // A pickup rebooked into a window that cannot collect this
                // order's pieces is refused (item 19) — marked full here.
                $room = $collection ? app(PieceLimits::class)->roomInWindow($slot, $date) : null;

                return [
                    'id' => $slot->id,
                    'from' => $slot->start_time,
                    'to' => $slot->end_time,
                    'label' => $slot->label(),
                    'applies_to' => $slot->applies_to,
                    'capacity' => $slot->capacity,
                    'remaining' => $remaining,
                    'pieces_left' => $room,
                    'is_full' => ($remaining !== null && $remaining < 1)
                        || ($room !== null && $room < max(1, (int) $order->estimated_items_count)),
                    // Today's window, ended or ending too soon: refused on rebook.
                    'closed' => $clock->isClosed($date, $slot),
                ] + $turnaround->windowFlags(
                    // Before the service's turnaround is up; null for a pickup.
                    // Never «too late»: a rebooking is not bounded by the
                    // booking window, which is measured from a pickup long past.
                    $earliest,
                    null,
                    $date,
                    $slot,
                );
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

        $collection = $task !== null && $task->type->onPickupWindow();

        $deliveryBefore = [$order->delivery_date?->toDateString(), $order->delivery_slot_id];

        try {
            $order = $service->reschedule($order, $request->user(), $validated);
        } catch (RuntimeException $e) {
            return match ($e->getMessage()) {
                'delivery_too_early' => failReturnMsg($this->deliveryProblemMessage($order, Turnaround::TOO_EARLY)),
                'no_delivery_after_pickup' => failReturnMsg(__('There is no delivery window free after this pickup in the coming weeks. Please choose an earlier pickup.')),
                'nothing_to_reschedule' => failReturnMsg(__('This order is not waiting for a new time.')),
                'slot_not_available' => failReturnMsg(__('That time is not available.')),
                'slot_full' => failReturnMsg(__('This window is fully booked. Please choose another one.')),
                'window_pieces_full' => failReturnMsg(__('This pickup window is fully booked. Please choose another one.')),
                'date_in_the_past' => failReturnMsg(__('Choose a date from today onwards.')),
                'slot_closed' => failReturnMsg(__('This window starts too soon to book. Please choose a later one.')),
                'not_your_order' => failReturnNotFound(__('Order not found.')),
                default => failReturnMsg(__('Could not set a new time.')),
            };
        }

        $slot = $collection ? $order->pickupSlot : $order->deliverySlot;
        $moved = $collection && [$order->delivery_date?->toDateString(), $order->delivery_slot_id] !== $deliveryBefore;

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
            // Set when the pickup moved far enough to push the delivery with
            // it — the service needs its time — so the app can say so. Null
            // when the delivery stayed where it was.
            'delivery_moved' => $moved ? [
                'date' => $order->delivery_date?->toDateString(),
                'time_slot' => $order->deliverySlot ? [
                    'id' => $order->deliverySlot->id,
                    'from' => $order->deliverySlot->start_time,
                    'to' => $order->deliverySlot->end_time,
                    'label' => $order->deliverySlot->label(),
                ] : null,
            ] : null,
        ], $moved
            ? __('Your new time is set. Your delivery moved to :date, :window, to leave the service its time.', [
                'date' => $order->delivery_date?->toDateString(),
                'window' => $order->deliverySlot?->label(),
            ])
            : ($collection
                ? __('Your new time is set. We will collect it then.')
                // A rebooked delivery — every delivery absence comes here since
                // 2026-11-11, and «we will collect it» told them the wrong trip.
                : __('Your new time is set. We will deliver it then.')));
    }

    /**
     * «الخدمة دي محتاجة 3 أيام — أبكر تسليم الخميس» / «التسليم ممكن يتحجز لحد …»
     * for a delivery rebooked outside what the service allows.
     */
    private function deliveryProblemMessage(Order $order, string $problem): string
    {
        return $order->service
            ? app(Turnaround::class)->message($order->service, $problem, $order->pickup_date, $order->pickupSlot)
            : __('That time is not available.');
    }
}
