<?php

namespace App\Modules\Order\Enums;

use App\Modules\Address\Models\Address;
use App\Modules\Laundry\Models\Laundry;
use App\Modules\Order\Models\Order;

/**
 * The four legs, in the order they happen.
 *
 * The sequence is fixed and the enum owns it, so nothing downstream has to
 * remember that collecting from the laundry comes after delivering to it.
 */
enum TaskType: string
{
    case PickupFromCustomer = 'pickup_from_customer';
    case DeliverToLaundry = 'deliver_to_laundry';
    case CollectFromLaundry = 'collect_from_laundry';
    case DeliverToCustomer = 'deliver_to_customer';

    /**
     * Position in the chain, 1 to 4.
     */
    public function sequence(): int
    {
        return match ($this) {
            self::PickupFromCustomer => 1,
            self::DeliverToLaundry => 2,
            self::CollectFromLaundry => 3,
            self::DeliverToCustomer => 4,
        };
    }

    /**
     * In creation order, which is also execution order.
     *
     * @return array<int, self>
     */
    public static function chain(): array
    {
        return [
            self::PickupFromCustomer,
            self::DeliverToLaundry,
            self::CollectFromLaundry,
            self::DeliverToCustomer,
        ];
    }

    /**
     * The app's «استلام» / «تسليم» filter.
     */
    public function isCollection(): bool
    {
        return in_array($this, [self::PickupFromCustomer, self::CollectFromLaundry], true);
    }

    /**
     * Whether this leg ends at a customer's door.
     *
     * Decides two things at once: that a signature is taken, and that the address
     * to navigate to is the customer's rather than the laundry's.
     */
    public function involvesCustomer(): bool
    {
        return in_array($this, [self::PickupFromCustomer, self::DeliverToCustomer], true);
    }

    /**
     * «توقيع العميل». Required on the customer-facing legs and meaningless on the
     * others — the design marks every optional field «(اختياري)» and marks neither
     * signature pad.
     */
    public function requiresSignature(): bool
    {
        return $this->involvesCustomer();
    }

    /**
     * Whether the driver counts pieces at this handover.
     *
     * Not on the final leg: the count was settled by the laundry's review and
     * agreed by the customer, and asking a driver to re-open it on the doorstep
     * would invite a dispute nobody there can resolve.
     */
    public function countsPieces(): bool
    {
        return $this !== self::DeliverToCustomer;
    }

    /**
     * Whether money changes hands here. Only the last leg, and only for COD.
     */
    public function collectsPayment(): bool
    {
        return $this === self::DeliverToCustomer;
    }

    /**
     * Which of `order_media`'s types a photo from this leg is filed under.
     */
    public function mediaType(): string
    {
        return match ($this) {
            self::PickupFromCustomer => 'pickup',
            self::DeliverToLaundry => 'laundry',
            self::CollectFromLaundry => 'ready',
            self::DeliverToCustomer => 'delivery',
        };
    }

    /**
     * The order status produced by *starting* this leg.
     *
     * Only the first: a driver setting off to collect is «في الطريق للاستلام», and
     * the customer's tracking screen has to say so while it is happening rather
     * than only afterwards. The other three legs start inside a stage the order is
     * already in.
     */
    public function startsInto(): ?OrderStatus
    {
        return $this === self::PickupFromCustomer ? OrderStatus::DriverOnWay : null;
    }

    /**
     * The order status this leg produces on completion, if any.
     *
     * Legs 2 and 3 move nothing: after the hand-over to the laundry the order is
     * waiting on the review (P7), and after collecting it the order is already
     * `ready_for_delivery`. Returning null here is what keeps the state machine
     * the single writer instead of scattering transitions through the task code.
     */
    public function completesInto(): ?OrderStatus
    {
        return match ($this) {
            self::PickupFromCustomer => OrderStatus::PickedUp,
            self::DeliverToCustomer => OrderStatus::Delivered,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PickupFromCustomer => 'Pick up from customer',
            self::DeliverToLaundry => 'Deliver to laundry',
            self::CollectFromLaundry => 'Collect from laundry',
            self::DeliverToCustomer => 'Deliver to customer',
        };
    }

    /**
     * Where the driver is going.
     */
    public function destinationFor(Order $order): ?string
    {
        return match ($this) {
            self::PickupFromCustomer => $order->pickupAddress?->street,
            self::DeliverToCustomer => $order->deliveryAddress?->street,
            default => $order->laundry?->address,
        };
    }

    /**
     * The same destination as a point on a map.
     *
     * Paired with `destinationFor()` on purpose — a street written for a human
     * and a coordinate written for a routing app are the same fact, and splitting
     * them across two places is how one of them ends up describing a different
     * leg from the other.
     *
     * **The laundry legs return real coordinates.** They used to be hardcoded
     * `null` in the task presenter while `laundries.lat` and `.lng` sat filled in
     * the table, so a driver could be routed to a customer's door and not to the
     * laundry — two of the four legs with no map at all.
     *
     * Null when nobody has placed the pin. The app must draw the address text
     * rather than a marker at (0, 0), which is in the Atlantic.
     *
     * @return array{lat: float, lng: float}|null
     */
    public function coordinatesFor(Order $order): ?array
    {
        return $this->pointOf(match ($this) {
            self::PickupFromCustomer => $order->pickupAddress,
            self::DeliverToCustomer => $order->deliveryAddress,
            default => $order->laundry,
        });
    }

    /**
     * One place that decides what «has a pin» means.
     *
     * The two sides differ and the difference is in the schema: `addresses.lat`
     * and `.lng` are NOT NULL — a customer cannot save an address without
     * dropping the pin — while `laundries.lat` and `.lng` are nullable, because
     * a laundry is created by an operator from a form that does not insist.
     *
     * @return array{lat: float, lng: float}|null
     */
    private function pointOf(Address|Laundry|null $point): ?array
    {
        if ($point === null || $point->lat === null || $point->lng === null) {
            return null;
        }

        return ['lat' => (float) $point->lat, 'lng' => (float) $point->lng];
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
