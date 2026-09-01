<?php

namespace App\Modules\Order\Enums;

/**
 * Where a leg has got to.
 *
 * `Pending` and `Assigned` are deliberately distinct even though both look idle:
 * pending means nobody has it, which is the dispatch queue's whole contents, and
 * a failed task returns to pending rather than staying assigned to the driver who
 * could not do it.
 */
enum TaskStatus: string
{
    case Pending = 'pending';
    case Assigned = 'assigned';
    case Started = 'started';
    case Completed = 'completed';
    case Failed = 'failed';

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
        return in_array($this, [self::Completed, self::Failed], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting a driver',
            self::Assigned => 'New',
            self::Started => 'In progress',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
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
