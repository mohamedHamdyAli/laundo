<?php

namespace App\Modules\Driver\Services;

use App\Modules\Driver\Models\DriverBonusRule;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\Order;

/**
 * Which terms a driver is on, and what one completed journey earns under them.
 *
 * The single source of truth for the immediate half of the bonus, so the rate a
 * driver is paid and the rate the screen shows cannot disagree. It replaces
 * `EarningService::DEFAULT_RATE` — a hardcoded 20% of the delivery fee, paid to
 * every driver on the platform, read from a setting that had no field on the
 * settings form and no seeder row. Nobody could see it and nobody could stop it.
 *
 * **No rule means no bonus.** Null is the default for every existing driver and
 * for every driver added from now on, because a bonus nobody agreed to is money
 * quietly leaving.
 */
class BonusResolver
{
    /**
     * The rule this driver is on, or null.
     *
     * An inactive rule is treated as no rule. Switching a rule off is how an
     * operator stops paying under it without having to walk every driver on it,
     * and «inactive but still paying» would make the toggle a lie.
     */
    public function ruleFor(?int $driverId): ?DriverBonusRule
    {
        if ($driverId === null) {
            return null;
        }

        $rule = DriverBonusRule::whereHas(
            'profiles',
            fn ($query) => $query->where('user_id', $driverId)
        )->first();

        return $rule?->isActive() === true ? $rule : null;
    }

    /**
     * What one completed leg pays, and the sum that explains it.
     *
     * Returns null when this leg earns nothing at all — no rule, an inactive
     * one, a basis that does not pay on this leg type, or an amount that rounds
     * to zero. `EarningService` writes no row for a null, which is what keeps a
     * driver's history free of lines reading «EGP 0.00».
     *
     * @return array{amount: float, basis: float, rate: float}|null
     *                                                              basis and rate are carried for the audit line: on a flat bonus
     *                                                              the basis is the amount itself and the rate is 1, which is the
     *                                                              literal truth of «one of these, at full value»
     */
    public function forLeg(?DriverBonusRule $rule, Order $order, TaskType $type): ?array
    {
        if ($rule === null || ! $rule->isActive() || ! $rule->hasImmediateBonus()) {
            return null;
        }

        if (! $rule->basis->paysOn($type)) {
            // A per-order bonus on the first three legs would pay the order
            // four times over.
            return null;
        }

        if ($rule->basis->isFlat()) {
            $amount = round((float) $rule->amount, 2);

            return $amount > 0
                ? ['amount' => $amount, 'basis' => $amount, 'rate' => 1.0]
                : null;
        }

        // A share of the delivery fee, split across the four journeys, so one
        // driver doing all four earns the whole share and two drivers splitting
        // the order each earn their half. The same arithmetic the platform used
        // before rules existed — kept because it is the only basis that follows
        // distance, the delivery fee being distance x the zone's per-km rate.
        $legBasis = round((float) $order->delivery_fee / 4, 2);
        $rate = round(max(min((float) $rule->rate, 100.0), 0.0) / 100, 4);
        $amount = round($legBasis * $rate, 2);

        return $amount > 0
            ? ['amount' => $amount, 'basis' => $legBasis, 'rate' => $rate]
            : null;
    }
}
