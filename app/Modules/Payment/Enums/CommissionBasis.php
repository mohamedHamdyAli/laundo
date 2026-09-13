<?php

namespace App\Modules\Payment\Enums;

/**
 * What one commission charge is measured on.
 *
 * Two cases, because a real contract is usually both at once — «10% of the
 * order, plus 5 EGP a job» — and a laundry carries as many rules as the
 * agreement needs. They add together.
 *
 * Deliberately NOT a third case for «a share of the delivery fee». The basis
 * every rule is measured against is the order total before tax, so a charge on
 * the delivery leg alone would be a second basis and a second thing to explain
 * on a dispute. If that is ever wanted it belongs as a column on the rule
 * naming the base, not as another case here.
 */
enum CommissionBasis: string
{
    case Percent = 'percent';
    case Fixed = 'fixed';

    public function label(): string
    {
        return match ($this) {
            self::Percent => 'A share of the order',
            self::Fixed => 'A fixed amount per order',
        };
    }

    public function short(): string
    {
        return match ($this) {
            self::Percent => 'Share',
            self::Fixed => 'Fixed',
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
