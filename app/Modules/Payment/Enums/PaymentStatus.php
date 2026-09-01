<?php

namespace App\Modules\Payment\Enums;

/**
 * Where one attempt to pay has got to.
 *
 * `Authorised` and `Captured` are separate because for card payments they are
 * separate events, and conflating them is how money gets reported as taken when
 * it has only been reserved.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Authorised = 'authorised';
    case Captured = 'captured';
    case Failed = 'failed';
    case Refunded = 'refunded';

    /**
     * Money has actually moved.
     */
    public function isSettled(): bool
    {
        return $this === self::Captured;
    }

    /**
     * Still capable of becoming a capture.
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Authorised], true);
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Captured, self::Failed, self::Refunded], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting payment',
            self::Authorised => 'Authorised',
            self::Captured => 'Paid',
            self::Failed => 'Failed',
            self::Refunded => 'Refunded',
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
