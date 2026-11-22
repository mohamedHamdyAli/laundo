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
     * Whether this leg runs on the order's **pickup** window (`pickup_slot_id`
     * / `pickup_date`) rather than its delivery window — the end a
     * postponement clears and a reschedule rebooks.
     */
    public function onPickupWindow(): bool
    {
        return in_array($this, [self::PickupFromCustomer, self::DeliverToLaundry], true);
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
     * Decides that the address to navigate to is the customer's rather than the
     * laundry's. (It used to decide the signature too; since 2026-11-08 that is
     * the delivery only — `requiresSignature()`.)
     */
    public function involvesCustomer(): bool
    {
        return in_array($this, [self::PickupFromCustomer, self::DeliverToCustomer], true);
    }

    /**
     * «توقيع العميل» — required on the delivery only.
     *
     * It was required on both legs at the customer's door. The client's sheet,
     * item 5 (2026-11-08): «إزالة شرط توقيع العميل لتأكيد عملية الإستلام من طرف
     * المندوب» — the owner kept it on the delivery, where it is the proof the
     * customer got their clothes back. A signature sent on the pickup is still
     * stored; it is simply no longer a condition of completing it.
     */
    public function requiresSignature(): bool
    {
        return $this === self::DeliverToCustomer;
    }

    /**
     * Whether the driver app asks for the bag's QR scan («مسح الكود») on this
     * leg before «تأكيد».
     *
     * The server has never enforced the scan — `verify` checks a code and
     * records nothing, `complete` does not ask for it — the app runs the step.
     * The client's sheet, item 6 (2026-11-08): «إزالة شرط الـ QR في حالة
     * الإستلام من المغسلة لعدم الحاجة له». So the leg says it, as
     * `requires_signature` does, and the app stops asking at the laundry.
     * `verify` still answers on every leg for an app that scans anyway.
     */
    public function requiresScan(): bool
    {
        return $this !== self::CollectFromLaundry;
    }

    /**
     * Whether the driver counts pieces at this handover: **the pickup from the
     * customer and the collection from the laundry**.
     *
     * - The pickup: what the customer handed over. Item 12 (2026-11-08) took
     *   this count away along with the signature, but the client asked only for
     *   the signature to go (QA on items 5 and 12, 2026-11-22: «تم إزالة الشرط
     *   و لكن تم أيضاً إزالة إضافة عدد القطع المستلمة من العميل»). A different
     *   count is a `PieceDiscrepancy`, never refused.
     * - The collection from the laundry: against the laundry's own count. A
     *   mismatch is held for the platform (`TaskHolds`, item 9).
     *
     * Not at the trip to the laundry: the laundry's review is what counts the
     * bag there (item 12, «فحص القطع من خلال المغسلة»), and a count sent on that
     * leg is ignored. Never on the final leg: the customer agreed the count.
     */
    public function countsPieces(): bool
    {
        return $this === self::PickupFromCustomer || $this === self::CollectFromLaundry;
    }

    /**
     * Whether the driver counts the hangers the clothes come out on: **no leg
     * any more**.
     *
     * From 2026-11-09 the laundry wrote a figure at «خلص الغسيل» and the
     * collection from the laundry was held to it. The client rejected that
     * (QA on item 11, 2026-11-22): the hangers the laundry confirms are the
     * ones the customer hands back, when the driver brings them in
     * (`HangerReceipt`), not the ones the finished clothes go out on. The
     * hanger-count hold stays readable for the holds raised before.
     */
    public function countsHangers(): bool
    {
        return false;
    }

    /**
     * Whether money may change hands here — the two legs at the customer's door.
     *
     * The delivery collects what is still owed; since «رسوم المشوار»
     * (2026-11-08) the pickup collects the pickup trip's fee on a cash order.
     * Whether *this* leg has anything to take is `OrderTask::toCollect()`.
     */
    public function collectsPayment(): bool
    {
        return $this === self::PickupFromCustomer || $this === self::DeliverToCustomer;
    }

    /**
     * Whether the driver asks for hangers back here — «شماعات راجعة».
     *
     * The two legs at the customer's door, the owner's answer: a customer hands
     * back the last order's hangers whenever a driver is standing there, at the
     * collection or at the delivery. Each one earns points (`OrderPoints`).
     */
    public function takesHangers(): bool
    {
        return $this === self::PickupFromCustomer || $this === self::DeliverToCustomer;
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
     * The order status this leg produces on completion.
     *
     * **All four, now.** This used to answer null for legs 2 and 3, on the
     * reasoning that «after collecting it the order is already
     * `ready_for_delivery`» — and nothing put it there. `allowedNext()` runs
     * `confirmed → cleaning → ready_for_delivery → delivered`, so leg 4 asked
     * for `confirmed → delivered`, the table refused it, and every order that
     * got as far as a price was stranded at `confirmed` for ever. Nineteen of
     * them on the live install, with `settleMoney()` — which fires at
     * `Completed` — never once running.
     *
     * Each of the two additions is an event, not a convenience: the clothes are
     * at the laundry, and the laundry has handed them back. Reading the status
     * off those keeps it a record of something that happened, which is the same
     * standard the other two already met.
     *
     * The state machine is still the single writer; this only says what to ask
     * it for, and `TaskService::advanceOrder()` still lets it refuse.
     */
    public function completesInto(): OrderStatus
    {
        return match ($this) {
            self::PickupFromCustomer => OrderStatus::PickedUp,

            // The clothes are at the laundry, so the work has started. Nothing
            // else says this: there is no «we began washing» endpoint and no
            // screen the laundry presses, and without it the order stopped dead
            // at `confirmed` — `DeliverToCustomer` then asked for
            // `confirmed → delivered`, the table refused, and the order was
            // stranded for ever with nobody paid. See tasks/todo.md.
            self::DeliverToLaundry => OrderStatus::Cleaning,

            // The driver has them back, which can only be true if the laundry
            // finished. Reading it off the collection rather than off a button
            // means the status is a record of something that happened.
            self::CollectFromLaundry => OrderStatus::ReadyForDelivery,

            self::DeliverToCustomer => OrderStatus::Delivered,
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
     * Where this leg **begins** — the other end from `coordinatesFor()`.
     *
     * What «the nearest driver» is measured to on the driver map: a leg waiting
     * for somebody is a place a driver has to get to first. The run to the
     * laundry starts at the customer's door, the run back starts at the
     * laundry, and the two doorstep legs start and end in the same place.
     *
     * @return array{lat: float, lng: float}|null
     */
    public function startCoordinatesFor(Order $order): ?array
    {
        return $this->pointOf($this->startsAtLaundry() ? $order->laundry : $order->pickupAddress);
    }

    /**
     * The two legs of the way back begin at the laundry; the two of the way
     * there begin at the customer's door.
     */
    public function startsAtLaundry(): bool
    {
        return in_array($this, [self::CollectFromLaundry, self::DeliverToCustomer], true);
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
