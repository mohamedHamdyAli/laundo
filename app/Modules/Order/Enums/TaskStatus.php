<?php

namespace App\Modules\Order\Enums;

/**
 * Where a leg has got to.
 *
 * `Pending` and `Assigned` are deliberately distinct even though both look idle:
 * pending means nobody has it, which is the dispatch queue's whole contents, and
 * a failed task returns to pending rather than staying assigned to the driver who
 * could not do it.
 *
 * `Cancelled` is not `Failed`. Failed is a driver who went and could not do it —
 * it counts against the attempt, it feeds the monthly bonus gates, and the leg
 * goes back to the queue for somebody else. Cancelled is a leg that is no longer
 * required at all because the order stopped, so nobody drove anywhere and nobody
 * is going to. Collapsing the two would book a failure against every driver
 * holding a leg of an order the customer called off.
 */
enum TaskStatus: string
{
    case Pending = 'pending';
    case Assigned = 'assigned';
    case Started = 'started';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * Sits in the dispatch queue waiting for a driver.
     */
    public function isUnassigned(): bool
    {
        return $this === self::Pending;
    }

    /**
     * Still to be done — the app's «الكل» minus the history.
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Assigned, self::Started], true);
    }

    /**
     * A driver may only begin a task that is theirs and has not begun.
     */
    public function isStartable(): bool
    {
        return $this === self::Assigned;
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Cancelled], true);
    }

    /**
     * Finished without anybody having driven anywhere.
     *
     * Kept apart from isFinished() because the two answer different questions:
     * the history screen lists all three, while anything measuring a driver's
     * work — the monthly bonus gates, the driver report — must not count a leg
     * that was called off as one they failed.
     */
    public function isCancelled(): bool
    {
        return $this === self::Cancelled;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting a driver',
            self::Assigned => 'New',
            self::Started => 'In progress',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
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
