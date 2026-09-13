<?php

namespace App\Modules\Driver\Enums;

use App\Modules\Order\Enums\TaskType;

/**
 * What a driver's immediate bonus is measured on.
 *
 * A closed list, because the calculator branches on it and a free-text basis is
 * a basis nobody can compute. Three cases, and each answers a different question
 * an operator actually asks:
 *
 *   - **per_order** — «كل طلب توصله = ٢٠ ج». The simplest thing to say to a
 *     driver, and the one they can check themselves.
 *   - **per_task** — the same, priced per journey. Right when different drivers
 *     take different legs of one order and each should be paid for their own.
 *   - **percent_delivery_fee** — what the platform paid before this existed.
 *     Kept because it is the only basis that scales with distance: the delivery
 *     fee is already distance x the zone's per-km rate.
 */
enum BonusBasis: string
{
    case PerOrder = 'per_order';
    case PerTask = 'per_task';
    case PercentDeliveryFee = 'percent_delivery_fee';

    public function label(): string
    {
        return match ($this) {
            self::PerOrder => 'A fixed amount per order',
            self::PerTask => 'A fixed amount per journey',
            self::PercentDeliveryFee => 'A share of the delivery fee',
        };
    }

    /**
     * The short form, for a table cell rather than a form option.
     */
    public function short(): string
    {
        return match ($this) {
            self::PerOrder => 'Per order',
            self::PerTask => 'Per journey',
            self::PercentDeliveryFee => 'Share of delivery',
        };
    }

    /**
     * True when the rule carries a flat sum rather than a percentage.
     *
     * The two are mutually exclusive on a rule and the form shows one box or the
     * other, so this is the question every caller is really asking.
     */
    public function isFlat(): bool
    {
        return $this !== self::PercentDeliveryFee;
    }

    /**
     * Whether a completed leg of this type earns anything under this basis.
     *
     * The whole of why `per_order` is not `per_task` with a bigger number: an
     * order has **four** journeys, and paying a flat «per order» amount on each
     * of them pays it four times. The final handover to the customer is the one
     * that means the order is done, so it is the one that pays.
     */
    public function paysOn(TaskType $type): bool
    {
        return match ($this) {
            self::PerOrder => $type === TaskType::DeliverToCustomer,
            self::PerTask, self::PercentDeliveryFee => true,
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
