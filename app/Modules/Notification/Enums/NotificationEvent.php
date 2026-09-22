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
     * A laundry has been given an order.
     *
     * Transactional: nothing else tells them. The customer is notified when
     * an order is placed and at every step after, and the driver is notified
     * when a journey is assigned — the laundry, which is the one who has to
     * actually clean the clothes, was told by nobody and had to notice by
     * refreshing its own panel.
     */
    case OrderAssignedToLaundry = 'order_assigned_to_laundry';

    /**
     * Last month's driver bonuses are worked out and waiting on a person.
     *
     * Aimed at operations, and raised **once per period**. The screen already
     * recomputes an open month on every visit, so this is not what makes the
     * figures right — it is what makes them noticed. Nothing about it approves
     * anything: a driver chasing last month's money is the cheapest way to lose
     * them, and a payout that ran on a schedule is a wrong payment made in the
     * month nobody was looking.
     */
    case DriverBonusReady = 'driver_bonus_ready';

    /**
     * Something a person sat down and wrote.
     *
     * Every other case here is a moment the system recognised. This one is the
     * absence of one — «اتأخرنا عليك، الأوردر في الطريق» has no trigger and never
     * will, and before this the only way to say it was to telephone.
     *
     * Deliberately **not** transactional. A hand-written message is the one kind
     * most likely to be noise, and a mute the panel can talk over is not a mute.
     * The in-app record is still written, because `database` is not a delivery
     * and was never what anybody asked to silence.
     */
    case ManualMessage = 'manual_message';

    /**
     * A driver's papers were refused.
     *
     * Transactional, and it is the clearest case of it in the list: the driver
     * is waiting on a screen that will not move until they send something else,
     * and they cannot know to until they are told. Silence here is not a quiet
     * app, it is a driver who re-sends the same blurred photograph and has it
     * refused for the same reason nobody gave them.
     *
     * There is no matching case for an approval on purpose. That one shows up as
     * the record simply being right on their own screen, and «your car is still
     * a Corolla» is the kind of message that teaches people to stop reading.
     */
    case DriverRecordRejected = 'driver_record_rejected';

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
            // The driver is blocked until they send something else.
            self::DriverRecordRejected,
            // A muted operator is still the person who has to answer a complaint.
            // This is an internal alert, not marketing.
            self::ComplaintReceived,
            // The order has stopped dead. Silencing this silences the only signal
            // that a laundry's machine time is being held by nobody's decision.
            self::PriceConfirmationSilent,
            // Nothing is collected until the customer picks a time, so a muted
            // customer would simply never be collected.
            self::RescheduleNeeded,
            // A laundry that does not know it has an order does not clean it.
            self::OrderAssignedToLaundry,
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
            self::OrderAssignedToLaundry => 'Order assigned to your laundry',
            self::DriverBonusReady => 'Driver bonuses ready',
            self::ManualMessage => 'Message from the team',
            self::DriverRecordRejected => 'Driver details refused',
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
