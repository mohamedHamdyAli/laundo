<?php

namespace App\Modules\Order\Enums;

/**
 * «ما الذي أعجبك؟» — the chips under the star rows.
 *
 * A closed set rather than free text, because the only question ever asked of
 * these is "how often was this picked", and free text cannot be counted.
 *
 * Note they are not all about the laundry: `easy_app` is about us and
 * `friendly_driver` is about the driver. That is fine for tags — they are
 * qualitative colour on a score, not the score itself — but it is the reason the
 * aspect *scores* are separate columns rather than being folded into one number.
 */
enum RatingTag: string
{
    case FastDelivery = 'fast_delivery';
    case EasyApp = 'easy_app';
    case GreatPackaging = 'great_packaging';
    case FriendlyDriver = 'friendly_driver';
    case VeryClean = 'very_clean';

    public function label(): string
    {
        return match ($this) {
            self::FastDelivery => 'Fast delivery',
            self::EasyApp => 'Easy app',
            self::GreatPackaging => 'Great packaging',
            self::FriendlyDriver => 'Friendly driver',
            self::VeryClean => 'Very clean',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
