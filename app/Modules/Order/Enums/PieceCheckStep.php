<?php

namespace App\Modules\Order\Enums;

/**
 * Where two counts of the same pieces disagreed: at one of the three counted
 * handovers, or when the laundry counted at its review a different number from
 * the one handed to it.
 */
enum PieceCheckStep: string
{
    case PickupFromCustomer = 'pickup_from_customer';
    case DeliverToLaundry = 'deliver_to_laundry';
    case CollectFromLaundry = 'collect_from_laundry';
    case LaundryReview = 'laundry_review';

    public static function forTask(TaskType $type): self
    {
        return self::from($type->value);
    }

    public function label(): string
    {
        return $this === self::LaundryReview
            ? "The laundry's review"
            : TaskType::from($this->value)->label();
    }
}
