<?php

namespace App\Modules\Wallet\Enums;

/**
 * Why money moved.
 *
 * A closed list, because «سجل المعاملات» in the design filters by exactly these
 * groups — الكل / المدفوعات / الإضافات / الاستردادات — and a free-text reason
 * cannot be filtered on.
 */
enum TransactionReason: string
{
    case TopUp = 'top_up';
    case OrderPayment = 'order_payment';
    case Refund = 'refund';
    case Withdrawal = 'withdrawal';
    case Earning = 'earning';
    case Adjustment = 'adjustment';

    /**
     * Which of the design's four tabs this belongs under.
     */
    public function group(): string
    {
        return match ($this) {
            self::TopUp, self::Earning => 'additions',
            self::OrderPayment, self::Withdrawal => 'payments',
            self::Refund => 'refunds',
            self::Adjustment => 'adjustments',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::TopUp => 'Wallet top-up',
            self::OrderPayment => 'Order payment',
            self::Refund => 'Refund',
            self::Withdrawal => 'Withdrawal',
            self::Earning => 'Delivery earning',
            self::Adjustment => 'Adjustment',
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
