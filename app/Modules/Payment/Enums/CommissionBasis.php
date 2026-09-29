<?php

namespace App\Modules\Payment\Enums;

/**
 * What a laundry's share is measured on.
 *
 * **Only `Percent` is live.** A rule is the laundry's share of the washing, and
 * the client's terms are a percentage of it. `Fixed` — a flat amount per order —
 * belonged to the time the rule was the platform's charge and could stack with
 * a percentage; it is kept only so the rules and settlement lines written under
 * it still read. No form offers it, and a fixed rule cannot be switched back on.
 */
enum CommissionBasis: string
{
    case Percent = 'percent';
    case Fixed = 'fixed';

    public function label(): string
    {
        return match ($this) {
            self::Percent => 'Laundry share of the washing',
            self::Fixed => 'A fixed amount per order (retired)',
        };
    }

    public function short(): string
    {
        return match ($this) {
            self::Percent => 'Laundry share',
            self::Fixed => 'Fixed (retired)',
        };
    }

    public function isFixed(): bool
    {
        return $this === self::Fixed;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
