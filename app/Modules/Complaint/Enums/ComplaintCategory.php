<?php

namespace App\Modules\Complaint\Enums;

/**
 * What the complaint is about.
 *
 * A closed set, so «أكتر سبب شكوى إيه» has an answer. Free text alone leaves that
 * question unanswerable unless somebody reads every row one at a time, which is
 * exactly what nobody does once there are a few hundred.
 *
 * `Other` exists deliberately. Without it a customer with a real problem that
 * does not fit picks the nearest wrong category, and the tally becomes a lie.
 */
enum ComplaintCategory: string
{
    case DamagedItem = 'damaged_item';
    case MissingItem = 'missing_item';
    case NotClean = 'not_clean';
    case Late = 'late';
    case DriverConduct = 'driver_conduct';
    case Payment = 'payment';
    case AppProblem = 'app_problem';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::DamagedItem => 'Damaged item',
            self::MissingItem => 'Missing item',
            self::NotClean => 'Not cleaned properly',
            self::Late => 'Late',
            self::DriverConduct => 'Driver conduct',
            self::Payment => 'Payment problem',
            self::AppProblem => 'App problem',
            self::Other => 'Something else',
        };
    }

    /**
     * Whether this kind of complaint is about a specific order.
     *
     * Used to nudge, not to enforce: a customer complaining about a damaged item
     * without naming the order leaves operations guessing, but refusing the
     * complaint outright would lose it entirely.
     */
    public function usuallyAboutAnOrder(): bool
    {
        return in_array($this, [
            self::DamagedItem,
            self::MissingItem,
            self::NotClean,
            self::Late,
            self::DriverConduct,
        ], true);
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
