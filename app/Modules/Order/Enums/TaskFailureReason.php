<?php

namespace App\Modules\Order\Enums;

/**
 * «سبب تعذر الاستلام» — the fixed list from the design's popup, plus free text.
 *
 * A closed list rather than a text box because these are the reasons operations
 * needs to count. «سبب آخر» exists for the rest, and carries a note.
 */
enum TaskFailureReason: string
{
    case CustomerUnavailable = 'customer_unavailable';
    case WrongAddress = 'wrong_address';
    case CustomerPostponed = 'customer_postponed';
    case PieceCountMismatch = 'piece_count_mismatch';
    case Other = 'other';

    /**
     * Whether this failure means the customer has to pick a new time.
     *
     * «طلب التأجيل» used to send the task straight back to the queue, so dispatch
     * offered it to the next driver within seconds — after the customer had just
     * said "not now". Nobody was waiting on anything; the same journey simply
     * failed again.
     *
     * A postponement is not a delivery problem to retry. It is a scheduling
     * decision that belongs to the customer, so the task stops and waits for them
     * to choose. The owner's decision.
     */
    /**
     * Whether this failure on this leg goes back to the customer for a new time
     * — the leg stops, its window is cleared, and nothing moves until they
     * pick one.
     *
     * A postponement always. **An absence at the pickup too, from the first
     * one** (the client's sheet, item 7, 2026-11-08: «في حالة تعذر إستلام
     * المندوب للأوردر من العميل لعدم وجوده يتم إعادة توجيه الأوردر للعميل
     * لتحديد موعد آخر للإستلام») — it used to go back to the drivers and only
     * reach a person after two attempts. An absence at the delivery keeps the
     * old path.
     */
    public function needsANewSlot(?TaskType $leg = null): bool
    {
        return $this === self::CustomerPostponed
            || ($this === self::CustomerUnavailable && $leg === TaskType::PickupFromCustomer);
    }

    /**
     * Whether this failure stops the order rather than the task.
     *
     * A disagreement about how many pieces there are is not a delivery problem:
     * sending another driver would move clothes that are already in dispute. It
     * halts and waits for a person.
     */
    public function haltsTheOrder(): bool
    {
        return $this === self::PieceCountMismatch;
    }

    public function label(): string
    {
        return match ($this) {
            self::CustomerUnavailable => 'Customer unavailable',
            self::WrongAddress => 'Wrong address',
            self::CustomerPostponed => 'Customer asked to postpone',
            self::PieceCountMismatch => 'Piece count does not match',
            self::Other => 'Other',
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
