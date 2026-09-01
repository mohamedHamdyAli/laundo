<?php

namespace App\Modules\Banner\Enums;

/**
 * Where a banner's «عرض التفاصيل» goes.
 *
 * The design has always had that button; the table never had anywhere to point
 * it, so operations could publish a banner nobody could act on. This is the
 * closed set of things a banner is allowed to open — deliberately closed, because
 * a free URL would send the customer out of the app and make it impossible to
 * say whether a banner ever produced an order.
 */
enum BannerTarget: string
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
