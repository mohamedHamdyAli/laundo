<?php

namespace App\Modules\Order\Enums;

/**
 * What a driver's count at a handover was compared with.
 *
 * Each leg is measured against the last number somebody stood behind: the
 * customer's own list at the first pickup, the count at the handover before,
 * and — once the laundry has reviewed the order — what the laundry counted.
 * So a mismatch names the step where a piece appeared or went missing, rather
 * than every later step repeating the first disagreement.
 */
enum PieceCountSource: string
{
    case CustomerOrder = 'customer_order';
    case PreviousLeg = 'previous_leg';
    case LaundryReview = 'laundry_review';
    // The platform looked into an earlier disagreement and said how many there
    // really are — that number stands until somebody counts differently.
    case Settled = 'settled';

    public function label(): string
    {
        return match ($this) {
            self::CustomerOrder => 'what the customer ordered',
            self::PreviousLeg => 'the count at the handover before',
            self::LaundryReview => "the laundry's count after its review",
            self::Settled => 'the count the platform settled',
        };
    }
}
