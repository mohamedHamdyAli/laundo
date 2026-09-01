<?php

namespace App\Modules\Notification\Enums;

/**
 * The moments worth telling somebody about.
 *
 * A closed list because the dashboard filters by it and the log counts by it, and
 * because an event nobody named is an event nobody can turn off.
 *
 * `isTransactional()` is the important one. A transactional message is one whose
 * absence **stalls something**: the customer who is never told the final price is
 * ready never confirms it, and their clothes sit in a laundry while everybody
 * waits for each other. Those ignore the mute switch — the design's «الإشعارات»
 * toggle silences noise, not the messages the order depends on.
 */
enum NotificationEvent: string
{
    case OrderPlaced = 'order_placed';
    case DriverOnWay = 'driver_on_way';
    case FinalPriceReady = 'final_price_ready';
    case PriceConfirmed = 'price_confirmed';
    case OrderReadyForDelivery = 'order_ready_for_delivery';
    case OrderDelivered = 'order_delivered';
    case RecurrencePrompt = 'recurrence_prompt';
    case TaskAssigned = 'task_assigned';
    case TaskQueuedTooLong = 'task_queued_too_long';
    case RefundDecided = 'refund_decided';
    case PriceQuestionAnswered = 'price_question_answered';

    /**
     * A complaint arrived.
     *
     * Aimed at operations, not the complainant. Without it the queue only works if
     * somebody remembers to open it, and a complaint sitting unseen for a day is
     * the exact failure the «waiting over a day» counter was added to measure —
     * measuring it is not the same as preventing it.
     */
    case ComplaintReceived = 'complaint_received';

    /**
     * A complaint was resolved or closed.
     *
     * Aimed at the complainant, and the counterpart to ComplaintReceived. The
     * decision was that operations answers by phone, so this is not the answer —
     * it is the acknowledgement that the case is finished, which is the difference
     * between "handled" and "handled, and they know".
     */
    case ComplaintClosed = 'complaint_closed';

    /**
     * A customer has not answered about the final price for a day.
     *
     * Aimed at operations. A distinct case rather than reusing FinalPriceReady so
     * the log can tell the first notification from the nudge — which is what makes
     * "once per order, ever" checkable at all.
     */
    case PriceConfirmationSilent = 'price_confirmation_silent';

    /**
     * A postponed order needs a new time from the customer.
     *
     * Transactional: the order does not move until they choose, so a muted
     * customer would simply never be collected.
     */
    case RescheduleNeeded = 'reschedule_needed';

    /**
     * Whether silence would stall something.
     */
    public function isTransactional(): bool
    {
        return in_array($this, [
            // The order stops dead until the customer answers.
            self::FinalPriceReady,
            // The whole point of the feature is the question.
            self::RecurrencePrompt,
            // A driver who does not know cannot go.
            self::TaskAssigned,
            // Nobody is watching the queue counter.
            self::TaskQueuedTooLong,
            // A muted operator is still the person who has to answer a complaint.
            // This is an internal alert, not marketing.
            self::ComplaintReceived,
            // The order has stopped dead. Silencing this silences the only signal
            // that a laundry's machine time is being held by nobody's decision.
            self::PriceConfirmationSilent,
            // Nothing is collected until the customer picks a time, so a muted
            // customer would simply never be collected.
            self::RescheduleNeeded,
        ], true);
    }

    /**
     * Which channels this event uses.
     *
     * SMS appears nowhere by decision: it is reserved for authentication, where
     * the cost buys security. Everything here reaches people in-app and by push.
     *
     * @return array<int, string>
     */
    public function channels(): array
    {
        return ['database', 'push'];
    }

    public function label(): string
    {
        return match ($this) {
            self::OrderPlaced => 'Order placed',
            self::DriverOnWay => 'Driver on the way',
            self::FinalPriceReady => 'Final price ready',
            self::PriceConfirmed => 'Price confirmed',
            self::OrderReadyForDelivery => 'Order ready for delivery',
            self::OrderDelivered => 'Order delivered',
            self::RecurrencePrompt => 'Wash reminder',
            self::TaskAssigned => 'Task assigned',
            self::TaskQueuedTooLong => 'Task waiting for a driver',
            self::RefundDecided => 'Refund decided',
            self::PriceQuestionAnswered => 'Price question answered',
            self::ComplaintReceived => 'Complaint received',
            self::ComplaintClosed => 'Complaint closed',
            self::PriceConfirmationSilent => 'Price confirmation overdue',
            self::RescheduleNeeded => 'New time needed',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
