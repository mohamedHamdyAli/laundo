<?php

namespace App\Modules\Complaint\Enums;

/**
 * Where a complaint has got to.
 *
 * Four states, not more. The customer sees this — not a reply, since the decision
 * is that operations answers by phone — so it has to be honest and short: it
 * arrived, somebody has it, it is done, or we closed it without acting.
 *
 * `Closed` and `Resolved` are separate on purpose. "We fixed it" and "we decided
 * not to act" are different outcomes, and collapsing them makes the resolved
 * count a number nobody can trust.
 */
enum ComplaintStatus: string
{
    case New = 'new';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::InProgress => 'Being handled',
            self::Resolved => 'Resolved',
            self::Closed => 'Closed without action',
        };
    }

    /** Still on somebody's desk. */
    public function isOpen(): bool
    {
        return in_array($this, [self::New, self::InProgress], true);
    }

    /**
     * The states this one may move to.
     *
     * A resolved complaint can be reopened — a customer calling back about the
     * same thing is common, and forcing a second complaint loses the history.
     *
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::New => [self::InProgress, self::Resolved, self::Closed],
            self::InProgress => [self::Resolved, self::Closed],
            self::Resolved, self::Closed => [self::InProgress],
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
