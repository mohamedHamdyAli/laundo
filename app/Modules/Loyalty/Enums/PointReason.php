<?php

namespace App\Modules\Loyalty\Enums;

/**
 * Why points moved.
 *
 * A closed list for the reason the wallet's is: the customer's history and the
 * panel's ledger both filter on it, and a free-text reason cannot be filtered.
 */
enum PointReason: string
{
    /** An order completed — `Points_Per_Order`, as stamped when it was placed. */
    case OrderEarned = 'order_earned';

    /** Hangers handed back to a driver at the door. */
    case HangersReturned = 'hangers_returned';

    /** Spent at checkout, as a discount on an order. */
    case Redeemed = 'redeemed';

    /**
     * Points spent on an order and given back: it was cancelled or returned,
     * or the laundry counted fewer pieces than the points had paid for.
     */
    case RedemptionReturned = 'redemption_returned';

    /** Added or taken by a person, with a note saying why. */
    case Adjustment = 'adjustment';

    /** A lot reached its expiry with points still on it. */
    case Expired = 'expired';

    /**
     * Which of the app's tabs it sits under: earned, spent or expired.
     */
    public function group(): string
    {
        return match ($this) {
            self::OrderEarned, self::HangersReturned, self::RedemptionReturned => 'earned',
            self::Redeemed => 'spent',
            self::Expired => 'expired',
            self::Adjustment => 'adjusted',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::OrderEarned => 'Points for an order',
            self::HangersReturned => 'Points for returned hangers',
            self::Redeemed => 'Points spent on an order',
            self::RedemptionReturned => 'Points returned from an order',
            self::Adjustment => 'Points adjustment',
            self::Expired => 'Points expired',
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
