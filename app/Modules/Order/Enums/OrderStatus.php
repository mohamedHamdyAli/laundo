<?php

namespace App\Modules\Order\Enums;

/**
 * The order lifecycle, and the only place the allowed moves are written down.
 *
 * The sequence and the customer-facing labels come from the design's tracking
 * screens: تم استلام الطلب → تمت مراجعة القطع → تأكيد السعر → جاري التنظيف →
 * جاهز للتوصيل → تم التوصيل, with «بانتظار استلام القطع» and «في الطريق للاستلام»
 * ahead of it.
 *
 * Four rules are encoded here rather than scattered through controllers:
 *
 *   - **Cancellation stops at pickup.** Once the driver has the clothes the order
 *     runs to its end. Putting it in the transition table means no endpoint can
 *     accidentally allow it.
 *   - **Confirmation releases cleaning, not payment.** `Confirmed` is the customer
 *     agreeing to the final price — «تم تأكيد الطلب، وسيبدأ تجهيز طلبك الآن». Money
 *     lives beside the pipeline in `payment_status`, because «الدفع نقدًا عند
 *     الاستلام» is on offer: a COD customer pays at delivery, long after cleaning,
 *     and must not be stuck here meanwhile.
 *   - **A questioned price is a state, not a note.** `ReviewDisputed` is
 *     «طلب مراجعة إضافية» — it gives the laundry a queue of orders to re-count,
 *     which is the entire point of offering the customer that button.
 *   - **Returned is terminal and operator-only.** There is no customer-facing
 *     rejection; it is the escape hatch for an argument that re-counting cannot
 *     settle. Not a cancellation: the pieces travel back and the delivery fee is
 *     still owed, so calling it cancelled would lose that.
 */
enum OrderStatus: string
{
    case AwaitingPickup = 'awaiting_pickup';
    case DriverOnWay = 'driver_on_way';
    case PickedUp = 'picked_up';
    case Reviewed = 'reviewed';
    case ReviewDisputed = 'review_disputed';
    case Confirmed = 'confirmed';
    case Cleaning = 'cleaning';
    case ReadyForDelivery = 'ready_for_delivery';
    case Delivered = 'delivered';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Returned = 'returned';

    /**
     * Where an order may go from here.
     *
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::AwaitingPickup => [self::DriverOnWay, self::Cancelled],
            // The last point a customer may still pull out.
            self::DriverOnWay => [self::PickedUp, self::Cancelled],
            self::PickedUp => [self::Reviewed],
            // The customer confirms, questions the count, or — operator only —
            // the argument ends and the pieces go back.
            self::Reviewed => [self::Confirmed, self::ReviewDisputed, self::Returned],
            // A re-count returns it to the customer with a fresh price.
            self::ReviewDisputed => [self::Reviewed, self::Returned],
            // Confirmed releases the work. Payment is tracked separately.
            self::Confirmed => [self::Cleaning],
            self::Cleaning => [self::ReadyForDelivery],
            self::ReadyForDelivery => [self::Delivered],
            self::Delivered => [self::Completed],
            self::Completed, self::Cancelled, self::Returned => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedNext(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedNext() === [];
    }

    /**
     * Whether the customer may still cancel.
     *
     * Deliberately derived from the transition table rather than a second list,
     * so the two can never disagree.
     */
    public function isCancellable(): bool
    {
        return $this->canTransitionTo(self::Cancelled);
    }

    /**
     * An order still moving through the pipeline — what the design's «نشط» tab
     * shows.
     */
    public function isActive(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * True once the clothes are physically with us.
     *
     * The point of no return, and the reason cancellation stops here.
     */
    public function isInCustody(): bool
    {
        return ! in_array($this, [self::AwaitingPickup, self::DriverOnWay, self::Cancelled], true);
    }

    /**
     * True while the laundry may price the pieces.
     *
     * Both the first review and a re-count after «طلب مراجعة إضافية».
     */
    public function isReviewable(): bool
    {
        return in_array($this, [self::PickedUp, self::ReviewDisputed], true);
    }

    /**
     * True while the customer is being asked to agree to the final price.
     */
    public function isAwaitingCustomer(): bool
    {
        return $this === self::Reviewed;
    }

    /**
     * The customer-facing label, keyed for translation.
     */
    public function label(): string
    {
        return match ($this) {
            self::AwaitingPickup => 'Awaiting pickup',
            self::DriverOnWay => 'Driver on the way',
            self::PickedUp => 'Picked up',
            self::Reviewed => 'Pieces reviewed',
            self::ReviewDisputed => 'Awaiting a second review',
            self::Confirmed => 'Price confirmed',
            self::Cleaning => 'Cleaning',
            self::ReadyForDelivery => 'Ready for delivery',
            self::Delivered => 'Delivered',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::Returned => 'Returned',
        };
    }

    /**
     * How the dashboard colours this status.
     *
     * Returns a tone token — `ok`, `bad`, `warn`, `live` — rather than a CSS
     * class, so the same answer drives the status pill and the stripe down the
     * leading edge of the row without either having to parse the other's
     * markup.
     *
     * It lives here rather than in Blade because two views drew the badge and
     * both used the same `isTerminal() ? bg-secondary : bg-info` ternary — which
     * gave «Completed» and «Cancelled» the identical grey. The two most opposite
     * outcomes an order has looked the same in a column whose entire job is
     * being scanned, and six in-progress states shared one cyan besides.
     *
     * The groups are the ones the state machine already draws: it ended as
     * intended, it ended without the work being delivered, somebody is being
     * waited on, or it is still moving.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Completed => 'ok',
            self::Cancelled, self::Returned => 'bad',
            // Waiting on a person: the customer agreeing a price, or a second
            // count being made. These are the rows an operator chases.
            self::Reviewed, self::ReviewDisputed => 'warn',
            default => 'live',
        };
    }

    /**
     * The points the design's tracking timeline draws.
     *
     * Six rather than the five of any single mock, because the design's three
     * timelines disagree about which five: `413:5365` omits تأكيد السعر while
     * `416:6541` and `399:2762` both include it. Confirmation is a step the
     * customer has to *act* on, so leaving it off the line would hide from them
     * the one place the order is waiting on nobody but themselves.
     *
     * `ReviewDisputed` is deliberately absent — it is a detour, not a milestone,
     * and drawing it would make the line grow when things go wrong.
     *
     * @return array<int, self>
     */
    public static function trackingSteps(): array
    {
        return [
            self::PickedUp,
            self::Reviewed,
            self::Confirmed,
            self::Cleaning,
            self::ReadyForDelivery,
            self::Delivered,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
