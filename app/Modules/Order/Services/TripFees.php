<?php

namespace App\Modules\Order\Services;

use App\Modules\Order\Models\Order;
use App\Modules\Order\Repositories\OrderRepository;

/**
 * «رسوم المشوار» — what each visit to the customer costs (the client's sheet,
 * items 3 and 4, 2026-11-08).
 *
 * The owner's words: «لما الدليفري يروح يستلم من العميل ياخد منه 25 جنيه، وبعد
 * كدا يروح المغسلة … ويرجع يسلم للعميل ياخد منه 25 برضه — بتتحدد من الداشبورد».
 *
 * - **One figure per trip**, `Trip_Fee` on the settings screen (blank is 25, 0
 *   is free). The pickup is a trip and the return is a trip; the order's
 *   `delivery_fee` is the two added up, so everything that read the delivery
 *   fee before reads the whole journey still.
 * - **A visit shared is charged once** (item 4): another of the customer's
 *   orders on the same address, day and window, still waiting for that kind of
 *   trip and carrying its fee, makes this one's trip free. Pickups with
 *   pickups, returns with returns — the owner's framing. No date or window, no
 *   sharing: there is no «same time» to share.
 * - **The fee moves when its carrier is cancelled** (`handOverFrom()`), to the
 *   next order still making that trip, unpaid — otherwise two orders and one
 *   cancellation would be a free visit.
 *
 * Not the distance any more: per-km zone rates price only orders placed before
 * this (`pickup_fee` null) — see `DeliveryFeeCalculator`.
 */
class TripFees
{
    public const DEFAULT_FEE = 25.0;

    public function __construct(private readonly OrderRepository $orders) {}

    /**
     * The fee for one trip, as set on the settings screen.
     */
    public function perTrip(): float
    {
        $value = getSettingValue('Trip_Fee');

        return is_numeric($value) ? round(max(0.0, (float) $value), 2) : self::DEFAULT_FEE;
    }

    /**
     * What an order's two trips cost, given the customer's other orders.
     *
     * @return array{pickup: float, return: float, total: float}
     */
    public function forOrder(
        int $customerId,
        int $pickupAddressId,
        int $deliveryAddressId,
        ?string $pickupDate,
        ?int $pickupSlotId,
        ?string $deliveryDate,
        ?int $deliverySlotId,
        ?int $exceptOrderId = null,
    ): array {
        $fee = $this->perTrip();

        $pickup = $this->carried('pickup', $customerId, $pickupAddressId, $pickupDate, $pickupSlotId, $exceptOrderId) ? 0.0 : $fee;
        $return = $this->carried('return', $customerId, $deliveryAddressId, $deliveryDate, $deliverySlotId, $exceptOrderId) ? 0.0 : $fee;

        return ['pickup' => $pickup, 'return' => $return, 'total' => round($pickup + $return, 2)];
    }

    /**
     * Where a cancelled order's trip fees go: to the first order still making
     * the same trip at the same visit that is not paid and carries none —
     * unless one of them already carries it. Under the rows' locks; the caller
     * applies the move in the same transaction.
     *
     * @return array<int, array{order: Order, trip: 'pickup'|'return', fee: float}>
     */
    public function handOverFrom(Order $cancelled, array $trips = ['pickup', 'return']): array
    {
        if ($cancelled->pickup_fee === null) {
            // Priced the old way: it carried no trip of anybody else's.
            return [];
        }

        $moves = [];

        foreach ($trips as $trip) {
            $column = $this->column($trip);
            $fee = (float) $cancelled->{$column};
            [$address, $date, $slot] = $trip === 'pickup'
                ? [$cancelled->pickup_address_id, $cancelled->pickup_date, $cancelled->pickup_slot_id]
                : [$cancelled->delivery_address_id, $cancelled->delivery_date, $cancelled->delivery_slot_id];

            if ($fee <= 0 || ! $address || ! $date || ! $slot) {
                continue;
            }

            $others = $this->orders->sharingVisit(
                $trip,
                (int) $cancelled->user_id,
                (int) $address,
                $date->toDateString(),
                (int) $slot,
                $cancelled->id,
                lock: true,
            );

            if ($others->contains(fn (Order $o) => (float) $o->{$column} > 0)) {
                continue;
            }

            $heir = $others->first(fn (Order $o) => $o->{$column} !== null && $o->payment_status !== 'paid');

            if ($heir) {
                $moves[] = ['order' => $heir, 'trip' => $trip, 'fee' => $fee];
            }
        }

        return $moves;
    }

    /**
     * @param  'pickup'|'return'  $trip
     */
    public function column(string $trip): string
    {
        return $trip === 'pickup' ? 'pickup_fee' : 'return_fee';
    }

    private function carried(string $trip, int $customerId, int $addressId, ?string $date, ?int $slotId, ?int $exceptOrderId): bool
    {
        if (! $date || ! $slotId) {
            return false;
        }

        $column = $this->column($trip);

        return $this->orders
            ->sharingVisit($trip, $customerId, $addressId, $date, $slotId, $exceptOrderId)
            ->contains(fn (Order $o) => (float) $o->{$column} > 0);
    }
}
