<?php

namespace App\Modules\Notification\Enums;

/**
 * Who a hand-written message can be addressed to.
 *
 * Three, because three is what the panel actually holds: the people who order,
 * the people who drive, and the shops. Operators are deliberately absent — the
 * bell already carries the system's own alerts and a screen for messaging
 * colleagues is a chat application, not a notification log.
 *
 * A laundry is the odd one out and the reason this is an enum rather than a role
 * slug: it is **not a user**. Choosing one resolves to its active owner and
 * staff, exactly as `OrderNotifier::orderAssignedToLaundry()` does — on a shop
 * with shifts the owner is the least likely of them to be looking.
 */
enum NotificationAudience: string
{
    case Customer = 'customer';
    case Driver = 'driver';
    case Laundry = 'laundry';

    /**
     * The role whose users *are* this audience, or null when the audience is not
     * a role at all.
     */
    public function roleSlug(): ?string
    {
        return match ($this) {
            self::Customer => 'user',
            self::Driver => 'driver',
            self::Laundry => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Customers',
            self::Driver => 'Drivers',
            self::Laundry => 'Laundries',
        };
    }

    /**
     * What the «everyone» option is called, said in full.
     *
     * «All» on its own beside a list of names reads as a filter. This is not a
     * filter — it is the difference between telling one person something and
     * telling every one of them.
     */
    public function everyoneLabel(): string
    {
        return match ($this) {
            self::Customer => 'Every customer',
            self::Driver => 'Every driver',
            self::Laundry => 'Every laundry',
        };
    }

    public function chooseLabel(): string
    {
        return match ($this) {
            self::Customer => 'Choose a customer',
            self::Driver => 'Choose a driver',
            self::Laundry => 'Choose a laundry',
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
