<?php

namespace App\Modules\Payment\Enums;

/**
 * The four the design offers: بطاقة بنكية · المحفظة الإلكترونية · انستا باي ·
 * الدفع نقدًا عند الاستلام.
 */
enum PaymentMethod: string
{
    case Card = 'card';
    case Wallet = 'wallet';
    case InstaPay = 'instapay';
    case Cash = 'cash';

    /**
     * Whether this method goes through a payment provider at all.
     *
     * Cash does not: it is collected at the door by a driver, and P8 already
     * settles it. Trying to route it through a gateway would invent a transaction
     * that never existed.
     */
    public function usesGateway(): bool
    {
        return $this !== self::Cash;
    }

    public function label(): string
    {
        return match ($this) {
            self::Card => 'Bank card',
            self::Wallet => 'E-wallet',
            self::InstaPay => 'InstaPay',
            self::Cash => 'Cash on delivery',
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
