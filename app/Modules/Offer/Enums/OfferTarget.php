<?php

namespace App\Modules\Offer\Enums;

/**
 * Where tapping an offer card goes.
 *
 * The same closed set as `BannerTarget`, and closed for the same reasons: a
 * free URL would send the customer out of the app, and it would make it
 * impossible to ask afterwards whether an offer ever produced an order.
 *
 * Kept as its own enum rather than importing the banner's. They happen to hold
 * the same three cases today, but they answer to different screens — the day an
 * offer needs to open a category or a laundry, a shared enum would either grow
 * a case banners cannot honour or force the two to change together.
 */
enum OfferTarget: string
{
    /** Informational only. No button is rendered. */
    case None = 'none';

    /** Opens the service, so the customer lands mid-wizard on the thing advertised. */
    case Service = 'service';

    /** Opens the order summary with the code already applied. */
    case Coupon = 'coupon';

    public function label(): string
    {
        return match ($this) {
            self::None => 'No action',
            self::Service => 'Open a service',
            self::Coupon => 'Apply a discount code',
        };
    }

    /** Whether `target_value` must be present for this kind. */
    public function needsValue(): bool
    {
        return $this !== self::None;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
