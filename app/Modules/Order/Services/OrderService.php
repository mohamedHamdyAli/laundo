<?php

namespace App\Modules\Order\Services;

use App\Modules\Address\Models\Address;
use App\Modules\Coupon\Models\Coupon;
use App\Modules\Coupon\Services\CouponService;
use App\Modules\Laundry\Models\Laundry;
use App\Modules\Notification\Services\OrderNotifier;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderItem;
use App\Modules\Service\Models\Service;
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

        $laundry = $this->assigner->assign($pickup, $service);

        // Validated, not redeemed: most baskets a code is checked against are
        // never ordered, and consuming one here would spend a customer's single
        // use of a welcome code on a screen they walked away from.
        [$coupon, $discount, $couponError] = $this->resolveCoupon(
            $data['coupon_code'] ?? null,
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
    public function place(User $customer, array $data): Order
    {
        [$service, $pickup, $delivery] = $this->resolveContext($customer, $data);

        $laundry = $this->assigner->assign($pickup, $service);

        [$coupon, $discount] = $this->resolveCoupon(
            $data['coupon_code'] ?? null,
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

        return DB::transaction(function () use ($customer, $service, $pickup, $delivery, $laundry, $quote, $data, $coupon) {
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
                'estimated_total' => $quote['total'],
                // The code that actually applied, not the one that was typed.
                'coupon_code' => $coupon?->code,
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
                $order->update([
                    'delivery_fee' => $fee['fee'],
                    'estimated_total' => round(
                        (float) $order->estimated_subtotal + $fee['fee'] - (float) $order->discount_total,
                        2
                    ),
                ]);
            }

            $this->machine->note($order, "Assigned to laundry #{$laundryId}.", 'admin', $actor);

            return $order->refresh();
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
        $items = [];

        foreach ($order->estimatedItems as $line) {
            $items[] = ['item_id' => $line->item_id, 'qty' => $line->qty];
        }

        return [
            'service_id' => $order->service_id,
            'pickup_address_id' => $order->pickup_address_id,
            'delivery_address_id' => $order->delivery_address_id,
            'delivery_method' => $order->delivery_method,
            'special_instructions' => $order->special_instructions,
            'items' => $items,
        ];
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
