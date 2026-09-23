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
     * The platform's cut of an order, credited to the super admin.
     */
    case Commission = 'commission';

    /**
     * What is left of an order after the commission, credited to the laundry.
     *
     * A separate reason from `Earning`, which is the driver's. Both are money the
     * platform owes for work done on an order, and folding them together would
     * make «what did this laundry earn» unanswerable from the ledger — which is
     * the one question this reason exists to answer.
     */
    case LaundryPayout = 'laundry_payout';

    /**
     * The platform's fee on an order, credited to the super admin.
     *
     * A separate reason from `Commission` on purpose, and the distinction is the
     * whole point of the fee existing: the commission is charged to the laundry
     * and comes out of what it earned, this is charged to the customer and is
     * folded into the prices they were shown. Folding them together would make
     * «what are we charging our customers» and «what are we charging our
     * laundries» both unanswerable from the ledger.
     */
    case PlatformFee = 'platform_fee';

    /**
     * A driver's monthly performance bonus.
     *
     * Separate from `Earning`, which is the per-journey half. They are paid at
     * different moments, decided by different rules and approved by different
     * people — and «كام أخد بونس آخر الشهر» is a question the ledger has to be
     * able to answer without also counting every leg he drove.
     *
     * There is deliberately no reason for a **salary**: the owner's decision is
     * that salaries are paid entirely outside this system, so no wallet ever
     * holds one and no transaction can claim to.
     */
    case Bonus = 'bonus';

    /**
     * Which of the design's four tabs this belongs under.
     */
    public function group(): string
    {
        return match ($this) {
            self::TopUp, self::Earning, self::Commission, self::PlatformFee, self::LaundryPayout, self::Bonus => 'additions',
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
            self::Commission => 'Platform commission',
            self::LaundryPayout => 'Laundry share of an order',
            self::PlatformFee => 'Platform fee from the customer',
            self::Bonus => 'Monthly bonus',
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
