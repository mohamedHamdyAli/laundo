<?php

namespace App\Modules\Order\Services;

use App\Modules\Address\Models\Address;
use App\Modules\Coupon\Models\Coupon;
use App\Modules\Coupon\Services\CouponService;
use App\Modules\Laundry\Models\Laundry;
use App\Modules\Notification\Services\OrderNotifier;
use App\Modules\Offer\Models\Offer;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderItem;
use App\Modules\Service\Models\Service;
use App\Modules\TimeSlot\Models\TimeSlot;
use App\Modules\TimeSlot\Services\SlotCapacity;
use App\Modules\User\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Placing, cancelling and repeating an order.
 *
 * This is the customer's side of the lifecycle. The laundry's side — reviewing
 * the pieces and setting the final price — is P7, and the driver's four legs are
 * P8; both go through OrderStateMachine, not through here.
 *
 * The one invariant to hold on to: **what is written to the order is what the
 * customer was shown.** quote() and place() run the same pricing pass, so the
 * summary screen and the stored total cannot disagree.
 */
class OrderService
{
    public function __construct(
        private readonly OrderPricing $pricing,
        private readonly LaundryAssigner $assigner,
        private readonly OrderStateMachine $machine,
        private readonly TaskGenerator $tasks,
        private readonly CouponService $coupons,
        private readonly SlotCapacity $slots,
    ) {}

    /**
     * Price a basket without saving anything — the wizard's summary step.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function quote(User $customer, array $data): array
    {
        [$service, $pickup, $delivery] = $this->resolveContext($customer, $data);

        // The window and date go in too, and both call sites must pass them.
        // The choice now depends on how full each laundry is in that window, so
        // a quote that omitted them would rank on distance alone and could name
        // a different laundry — and therefore a different delivery fee — than
        // the submit a minute later.
        $laundry = $this->assigner->assign(
            $pickup,
            $service,
            $data['pickup_slot_id'] ?? null,
            $data['pickup_date'] ?? null,
        );

        // Validated, not redeemed: most baskets a code is checked against are
        // never ordered, and consuming one here would spend a customer's single
        // use of a welcome code on a screen they walked away from.
        [$coupon, $discount, $couponError] = $this->resolveCoupon(
            $this->discountCode($data),
            $customer,
            $service,
            $data['items'] ?? [],
            $pickup,
            $delivery,
            $laundry,
        );

        $quote = $this->pricing->quote(
            $service,
            $data['items'] ?? [],
            $pickup,
            $delivery,
            $laundry,
            $discount,
            // The summary screen re-quotes as the customer picks a method, so the
            // cash surcharge appears the moment they choose cash — and disappears
            // if they change their mind. A fee that only shows up on the receipt
            // is a fee they never agreed to.
            $data['payment_method'] ?? null,
        );

        return $quote + [
            'coupon_code' => $coupon?->code,
            'coupon_error' => $couponError,
            'laundry' => $laundry ? ['id' => $laundry->id, 'name' => getLocalizedValue($laundry, 'name')] : null,
            'service' => ['id' => $service->id, 'pricing_mode' => $service->pricing_mode],
        ];
    }

    /**
     * Create the order.
     *
     * @param  array<string, mixed>  $data
     */
    public function place(User $customer, array $data, bool $enforceSlotCapacity = true): Order
    {
        [$service, $pickup, $delivery] = $this->resolveContext($customer, $data);

        // The window and date go in too, and both call sites must pass them.
        // The choice now depends on how full each laundry is in that window, so
        // a quote that omitted them would rank on distance alone and could name
        // a different laundry — and therefore a different delivery fee — than
        // the submit a minute later.
        $laundry = $this->assigner->assign(
            $pickup,
            $service,
            $data['pickup_slot_id'] ?? null,
            $data['pickup_date'] ?? null,
        );

        [$coupon, $discount] = $this->resolveCoupon(
            $this->discountCode($data),
            $customer,
            $service,
            $data['items'] ?? [],
            $pickup,
            $delivery,
            $laundry,
        );

        // Same pricing pass as quote(), same payment method — so the total the
        // customer agreed to is the total that is stored.
        $quote = $this->pricing->quote(
            $service,
            $data['items'] ?? [],
            $pickup,
            $delivery,
            $laundry,
            $discount,
            $data['payment_method'] ?? null,
        );

        // A basket containing a piece this service is not priced for would
        // otherwise be silently short-charged.
        if ($quote['unpriced'] !== []) {
            throw new RuntimeException('unpriced_items:'.implode(',', $quote['unpriced']));
        }

        if ($service->isPerItem() && $quote['items_count'] < 1) {
            throw new RuntimeException('empty_basket');
        }

        return DB::transaction(function () use ($customer, $service, $pickup, $delivery, $laundry, $quote, $data, $coupon, $enforceSlotCapacity) {
            // Inside the transaction and before the insert: a window checked
            // before the write is a window two customers can both pass.
            if ($enforceSlotCapacity) {
                $this->slots->claim($data['pickup_slot_id'] ?? null, $data['pickup_date'] ?? null);
                $this->slots->claim($data['delivery_slot_id'] ?? null, $data['delivery_date'] ?? null);
            }

            $order = Order::create([
                'code' => Order::generateCode(),
                'user_id' => $customer->id,
                // Null when nothing covers the zone. Accepted by decision.
                'laundry_id' => $laundry?->id,
                'service_id' => $service->id,
                'status' => OrderStatus::AwaitingPickup,
                'pickup_address_id' => $pickup->id,
                'delivery_address_id' => $delivery->id,
                'pickup_slot_id' => $data['pickup_slot_id'] ?? null,
                'delivery_slot_id' => $data['delivery_slot_id'] ?? null,
                'pickup_date' => $data['pickup_date'] ?? null,
                'delivery_date' => $data['delivery_date'] ?? null,
                // Two legs, two answers. One column meant a customer who
                // wanted to hand the bag over in person and have the clean
                // clothes left at the door could not say so.
                'pickup_method' => $data['pickup_method'] ?? 'door',
                'delivery_method' => $data['delivery_method'] ?? 'door',
                'driver_note' => $data['driver_note'] ?? null,
                'special_instructions' => $data['special_instructions'] ?? null,
                // Recorded, not merely validated: this is the customer's consent
                // to being re-priced after the pieces are counted, and the date
                // it was given is the part that matters in a dispute.
                'review_terms_accepted_at' => ! empty($data['accepts_review_terms']) ? now() : null,
                'estimated_items_count' => $quote['items_count'],
                'estimated_subtotal' => $quote['subtotal'],
                // An unknowable fee is stored as 0 and re-derived on assignment;
                // the customer is shown the reason rather than a false figure.
                'delivery_fee' => $quote['delivery_fee'] ?? 0,
                'discount_total' => $quote['discount'],
                'cash_surcharge' => $quote['cash_surcharge'],
                // Already inside `estimated_subtotal`; recorded separately so the
                // settlement can take it back out. Same copy-at-placement rule as
                // the tax rate below — raising the fee next month must not change
                // how an order placed today is divided.
                'platform_fee' => $quote['platform_fee'],
                'platform_fee_rate' => $quote['platform_fee_rate'],
                // Copied onto the order for the same reason the unit prices are:
                // tax is charged at the rate in force on the day, and a state
                // that raises it next quarter must not restate this invoice.
                'tax_rate' => $quote['tax_rate'],
                'estimated_tax' => $quote['tax'],
                'estimated_total' => $quote['total'],
                // The code that actually applied, not the one that was typed.
                'coupon_code' => $coupon?->code,
                // Which offer won this order. The reason the offer targets are
                // a closed set in the first place — a discount with no
                // provenance makes «did that card ever sell anything» an
                // unanswerable question.
                'offer_id' => $data['offer_id'] ?? null,
                'payment_method' => $data['payment_method'] ?? null,
                'qr_token' => Order::generateQrToken(),
                'recurrence_id' => $data['recurrence_id'] ?? null,
            ]);

            foreach ($quote['lines'] as $line) {
                OrderItem::create($line + ['order_id' => $order->id, 'phase' => 'estimated']);
            }

            if ($coupon && $quote['discount'] > 0) {
                $this->coupons->redeem($coupon, $customer, $order, $quote['discount']);
            }

            $this->machine->open($order, 'customer', $customer);

            // The four journeys exist from the moment the order does. The first
            // thing that has to happen to a new order is somebody going to
            // collect it, so a chain created any later would be created after the
            // leg it was supposed to schedule.
            //
            // Three of the four are not doable yet — OrderTask::predecessorComplete()
            // is what holds them — but they are visible to the driver and to
            // operations, which is the point.
            $this->tasks->generate($order);

            // Outside the state machine because placing an order is not a
            // transition — there is no previous status to move from.
            $this->announcePlacement($order);

            // And the laundry, when the assigner found one. Nothing else tells
            // them: every other notification on an order goes to the customer
            // or the driver, and the party that has to clean the clothes was
            // left to notice by refreshing its own panel.
            if ($order->laundry_id) {
                $this->announceLaundryAssignment($order);
            }

            return $order;
        });
    }

    /**
     * Cancel, if the clothes are not with us yet.
     *
     * The window closes at pickup — that was the decision, and OrderStatus is
     * where it is written, so this method only reports it.
     */
    public function cancel(Order $order, User $actor, ?string $reason = null): Order
    {
        if (! $order->status->isCancellable()) {
            throw new RuntimeException('not_cancellable');
        }

        $cancelled = $this->machine->transition($order, OrderStatus::Cancelled, 'customer', $actor, $reason);

        // Nobody should be driving to collect an order that no longer exists.
        $this->tasks->cancelOpenTasks($cancelled);

        // An order cancelled before it was ever fulfilled should not have spent
        // the customer's one use of a welcome code.
        $this->coupons->release($cancelled);

        return $cancelled;
    }

    /**
     * Assign — or reassign — a laundry.
     *
     * Reprices delivery, because the fee is measured from the laundry and an
     * unassigned order was stored with 0. Only ever called before pickup, so no
     * agreed-and-collected order can have its total moved underneath it.
     */
    public function assignLaundry(Order $order, int $laundryId, ?User $actor = null): Order
    {
        if ($order->status->isInCustody()) {
            throw new RuntimeException('already_in_custody');
        }

        return DB::transaction(function () use ($order, $laundryId, $actor) {
            $order->laundry_id = $laundryId;
            $order->save();

            // Reload the relations the fee depends on: laundry has just changed,
            // and the addresses may never have been loaded.
            $order->unsetRelation('laundry')->load(['laundry', 'service', 'pickupAddress.zone', 'deliveryAddress.zone']);

            $laundry = $order->laundry;
            $pickup = $order->pickupAddress;

            // An empty basket: only the delivery leg is being repriced. The
            // pieces were priced when the order was placed and stay untouched.
            $fee = $this->pricing->deliveryFeeFor($laundry, $pickup, $order->deliveryAddress);

            if ($fee['fee'] !== null) {
                // Through the one assembler, at the order's OWN stored rate.
                // Adding the fee by hand here is how the cash surcharge came to
                // be dropped from a reassigned order's total, and re-reading the
                // tax setting would retax an agreed order at today's rate.
                $money = $this->pricing->compose(
                    (float) $order->estimated_subtotal,
                    (float) $fee['fee'],
                    (float) $order->discount_total,
                    (float) $order->cash_surcharge,
                    $order->taxRate(),
                );

                $order->update([
                    'delivery_fee' => $fee['fee'],
                    'estimated_tax' => $money['tax'],
                    'estimated_total' => $money['total'],
                ]);

                // The final figure, if the pieces have already been counted, is
                // measured from the same fee and moves with it.
                if ($order->hasFinalPrice()) {
                    $final = $this->pricing->compose(
                        (float) $order->final_subtotal,
                        (float) $fee['fee'],
                        (float) $order->discount_total,
                        (float) $order->cash_surcharge,
                        $order->taxRate(),
                    );

                    $order->update([
                        'final_tax' => $final['tax'],
                        'final_total' => $final['total'],
                    ]);
                }
            }

            $this->machine->note($order, "Assigned to laundry #{$laundryId}.", 'admin', $actor);

            $order->refresh();

            // The laundry that has just been given the work. Reassignment
            // included: the new one has to know, and the old one has already
            // been told by whoever moved it.
            $this->announceLaundryAssignment($order);

            return $order;
        });
    }

    /**
     * The payload for the design's «إعادة الطلب»: the same basket, ready for the
     * wizard, with nothing scheduled.
     *
     * A copy of intent, not of price — prices are re-read when the new order is
     * actually placed, since the old order's figures may be months stale.
     *
     * @return array<string, mixed>
     */
    public function reorderPayload(Order $order): array
    {
        $order->loadMissing([
            'pickupAddress', 'deliveryAddress',
            'pickupSlot', 'deliverySlot', 'estimatedItems.item:id,name',
        ]);

        // `load`, not `loadMissing`: the controller arrives with `service:id,name`
        // already loaded, and a constrained eager load silently returns null for
        // every column it left out. `pricing_mode` is one of them — so
        // `is_estimated` read as true for every order, which is the opposite of
        // the answer. `loadMissing` would have kept the truncated row.
        $order->load('service');

        $basket = [];

        foreach ($order->estimatedItems as $line) {
            $basket[] = ['item_id' => (int) $line->item_id, 'qty' => (int) $line->qty];
        }

        // **Today's prices, never the order's own.** The stored figures are what
        // this customer paid last month; re-opening the wizard on them would put
        // a number on screen we are not willing to honour. Same pass as the
        // quote screen, so the basket they are about to confirm is costed by the
        // code that will cost it again when they submit.
        $quote = $this->requoteFor($order, $basket);

        // The quote prices what it can price; the original order names what was
        // in the basket. Neither alone can draw a reviewable line, so they are
        // joined on the item — a piece the service no longer prices keeps its
        // quantity and comes back with null money rather than disappearing.
        $priced = collect($quote['lines'])->keyBy('item_id');

        $items = [];

        foreach ($order->estimatedItems as $line) {
            $today = $priced->get((int) $line->item_id);

            $items[] = [
                'item_id' => (int) $line->item_id,
                'item' => $line->item ? [
                    'id' => $line->item->id,
                    'name' => getLocalizedValue($line->item, 'name'),
                ] : null,
                'qty' => (int) $line->qty,
                'unit_price' => isset($today['unit_price']) ? (float) $today['unit_price'] : null,
                'line_total' => isset($today['line_total']) ? (float) $today['line_total'] : null,
            ];
        }

        return [
            'service_id' => $order->service_id,
            'service' => $order->service ? [
                'id' => $order->service->id,
                'name' => getLocalizedValue($order->service, 'name'),
                'pricing_mode' => $order->service->pricing_mode,
            ] : null,
            // Stated rather than inferred. A quoted service has no per-piece
            // prices at all, so its basket is empty by design — and the app was
            // reading that empty array as «nothing to reorder».
            'is_estimated' => $order->service !== null && ! $order->service->isPerItem(),

            'pickup_address_id' => $order->pickup_address_id,
            'pickup_address' => $this->addressPayload($order->pickupAddress),
            'delivery_address_id' => $order->delivery_address_id,
            'delivery_address' => $this->addressPayload($order->deliveryAddress),
            'same_address' => $order->isRoundTrip(),
            'pickup_method' => $order->pickup_method,
            'delivery_method' => $order->delivery_method,

            // The windows the customer used last time, as defaults. The dates are
            // deliberately absent: a date from a past order is not a booking the
            // wizard can open on, and capacity is per window per day.
            'pickup_slot_id' => $order->pickup_slot_id,
            'pickup_slot' => $this->slotPayload($order->pickupSlot),
            'delivery_slot_id' => $order->delivery_slot_id,
            'delivery_slot' => $this->slotPayload($order->deliverySlot),
            // The app reads the pickup window under the bare name.
            'time_slot_id' => $order->pickup_slot_id,
            'time_slot' => $this->slotPayload($order->pickupSlot),

            'payment_method' => $order->payment_method,
            'special_instructions' => $order->special_instructions,
            // The app reads the instructions under this name.
            'notes' => $order->special_instructions,
            'driver_note' => $order->driver_note,

            // What was used last time — offered back so the app can tell the
            // customer whether it still applies, which is the quote's job and
            // not this endpoint's. Re-quoting with the code would spend a
            // single-use coupon on a screen the customer may walk away from.
            'coupon_code' => $order->coupon_code,
            'offer_id' => $order->offer_id,

            'items' => $items,

            // Null when the basket could not be re-priced at all — see below.
            'pricing' => $quote['pricing'],
            'pricing_error' => $quote['error'],
        ];
    }

    /**
     * Re-price an old basket at today's prices, tolerantly.
     *
     * `quote()` throws when the context no longer holds — the service was
     * deactivated, the address was deleted since. On the wizard that is the
     * right answer: there is nothing to price. On *reorder* it is not, because
     * the customer asked to see a previous order and a 500 on that screen tells
     * them nothing. So the failure is caught, named, and the rest of the order
     * is handed back priceless rather than not at all.
     *
     * @param  array<int, array{item_id: int, qty: int}>  $basket
     * @return array{lines: array<int, array<string, mixed>>, pricing: array<string, mixed>|null, error: string|null}
     */
    private function requoteFor(Order $order, array $basket): array
    {
        $customer = $order->customer;

        if ($customer === null) {
            return ['lines' => [], 'pricing' => null, 'error' => 'customer_not_found'];
        }

        try {
            $quote = $this->quote($customer, [
                'service_id' => $order->service_id,
                'pickup_address_id' => $order->pickup_address_id,
                'delivery_address_id' => $order->delivery_address_id,
                'items' => $basket,
                'payment_method' => $order->payment_method,
            ]);
        } catch (RuntimeException $e) {
            return ['lines' => [], 'pricing' => null, 'error' => $e->getMessage()];
        }

        return [
            'lines' => $quote['lines'],
            'pricing' => [
                'items_count' => $quote['items_count'],
                'subtotal' => $quote['subtotal'],
                'delivery_fee' => $quote['delivery_fee'],
                'delivery_fee_reason' => $quote['delivery_fee_reason'],
                // No coupon is applied here — see `coupon_code` above — so this
                // is zero by construction. Sent so the block has the same shape
                // as the quote screen's, which the app already draws.
                'discount' => $quote['discount'],
                'cash_surcharge' => $quote['cash_surcharge'],
                'tax_rate' => $quote['tax_rate'],
                'tax' => $quote['tax'],
                'pre_tax_total' => $quote['pre_tax_total'],
                'total' => $quote['total'],
                // Pieces this service no longer prices. They keep their line
                // above with null money; this is the machine-readable half.
                'unpriced_item_ids' => $quote['unpriced'],
            ],
            'error' => null,
        ];
    }

    /**
     * An address the customer can read, not an id they cannot.
     *
     * @return array<string, mixed>|null
     */
    private function addressPayload(?Address $address): ?array
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
     * @return array<string, mixed>|null
     */
    private function slotPayload(?TimeSlot $slot): ?array
    {
        if ($slot === null) {
            return null;
        }

        return [
            'id' => $slot->id,
            'label' => $slot->label(),
            'from' => $slot->start_time,
            'to' => $slot->end_time,
        ];
    }

    /**
     * Tell the laundry it has work. Swallowed for the same reason as the
     * placement announcement below it.
     */
    private function announceLaundryAssignment(Order $order): void
    {
        try {
            app(OrderNotifier::class)->orderAssignedToLaundry($order);
        } catch (\Throwable $e) {
            Log::warning('[notifications] laundry assignment', [
                'order' => $order->id, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Never fatal: an order that exists and was not announced is recoverable, an
     * order that failed to save because a notification did is not.
     */
    private function announcePlacement(Order $order): void
    {
        try {
            app(OrderNotifier::class)->orderPlaced($order);
        } catch (\Throwable $e) {
            Log::warning('[notifications] order placement', [
                'order' => $order->id, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Work out what a coupon code is worth on this basket, if anything.
     *
     * Returns the coupon, the discount, and — for the quote screen — why a code
     * was refused. A refused code is never fatal to the order: the customer asked
     * for a discount, not for the order to fail.
     *
     * @param  array<int, array{item_id: int, qty: int}>  $items
     * @return array{0: Coupon|null, 1: float, 2: string|null}
     */
    /**
     * Which code the discount comes from.
     *
     * An offer from the home carousel that points at a coupon *is* the
     * discount — the card promised it, so the customer should not have to type
     * anything. A code sent alongside such an offer never reaches here: the
     * request refuses it, because one discount per order.
     *
     * An offer pointing at a service carries no coupon, so whatever the
     * customer typed stands.
     *
     * @param  array<string, mixed>  $data
     */
    private function discountCode(array $data): ?string
    {
        $typed = $data['coupon_code'] ?? null;
        $offerId = $data['offer_id'] ?? null;

        if (! $offerId) {
            return $typed;
        }

        // `live()`, not `find()`: an expired offer is not a discount, and its
        // stale code must not be spent on an order placed after it ended.
        $offer = Offer::live()->with('coupon')->find($offerId);

        return $offer?->coupon?->isRedeemable()
            ? $offer->coupon->code
            : $typed;
    }

    private function resolveCoupon(
        ?string $code,
        User $customer,
        Service $service,
        array $items,
        Address $pickup,
        ?Address $delivery,
        ?Laundry $laundry,
    ): array {
        if (! $code) {
            return [null, 0.0, null];
        }

        // Priced once without a discount, to know what the discount applies to.
        $base = $this->pricing->quote($service, $items, $pickup, $delivery, $laundry);

        try {
            $result = $this->coupons->validate(
                $code,
                $customer,
                $base['subtotal'],
                (float) ($base['delivery_fee'] ?? 0),
            );
        } catch (RuntimeException $e) {
            return [null, 0.0, $this->coupons->message($e->getMessage())];
        }

        return [$result['coupon'], $result['discount'], null];
    }

    /**
     * Resolve and authorise the pieces an order is built from.
     *
     * Addresses are fetched **through the customer's own relation**, so an id
     * belonging to someone else is simply not found — the same rule as
     * AddressController.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: Service, 1: Address, 2: Address}
     */
    private function resolveContext(User $customer, array $data): array
    {
        $service = Service::where('status', 'active')->find($data['service_id'] ?? null);

        if (! $service) {
            throw new RuntimeException('service_not_found');
        }

        $pickup = $customer->addresses()->with('zone')->find($data['pickup_address_id'] ?? null);

        if (! $pickup) {
            throw new RuntimeException('pickup_address_not_found');
        }

        $deliveryId = $data['delivery_address_id'] ?? null;

        // The design's «التوصيل لنفس العنوان» toggle: absent or identical means one
        // address, which is also what keeps the 1.5x multiplier off.
        $delivery = $deliveryId && (int) $deliveryId !== $pickup->id
            ? $customer->addresses()->with('zone')->find($deliveryId)
            : $pickup;

        if (! $delivery) {
            throw new RuntimeException('delivery_address_not_found');
        }

        return [$service, $pickup, $delivery];
    }
}
