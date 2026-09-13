<?php

namespace App\Modules\Driver\Services;

use App\Modules\Driver\Enums\BonusBasis;
use App\Modules\Driver\Models\DriverBonusTier;
use App\Modules\Driver\Repositories\DriverBonusRuleRepository;
use Illuminate\Support\Facades\DB;

/**
 * Business rules for «قواعد البونس».
 *
 * The interesting part is `syncTiers()`: the tiers arrive as two parallel arrays
 * from a form where rows can be added and removed, so a save has to reconcile a
 * list rather than update a record.
 */
class driverBonusRuleCrudService
{
    public function __construct(private readonly DriverBonusRuleRepository $rules) {}

    public function getAllRules()
    {
        return $this->rules->getAll();
    }

    public function searchRules($query)
    {
        return $this->rules->search($query);
    }

    /**
     * The universal view-data assembler: the list under its plural key, and —
     * when an id is given — the single record under `row`.
     *
     * @return array<string, mixed>
     */
    public function shredData($id = null)
    {
        $data = ['bases' => BonusBasis::cases()];

        if ($id) {
            $data['row'] = $this->rules->find($id);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addRule(array $data)
    {
        return DB::transaction(function () use ($data) {
            $tiers = $this->pullTiers($data);
            $rule = $this->rules->create($this->normalise($data));

            $this->syncTiers($rule->id, $tiers);

            return $rule;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateRule(array $data)
    {
        return DB::transaction(function () use ($data) {
            $tiers = $this->pullTiers($data);
            $rule = $this->rules->update($data['id'], $this->normalise($data));

            $this->syncTiers($rule->id, $tiers);

            return $rule;
        });
    }

    public function deleteRule($id)
    {
        return $this->rules->delete($id);
    }

    public function toggleStatus($id, $status)
    {
        return $this->rules->update($id, [
            'status' => $status === 'active' ? 'active' : 'inactive',
        ]);
    }

    /**
     * Take the tier rows out of the payload before it reaches the rule.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array{min_orders: int, amount: float}>
     */
    private function pullTiers(array &$data): array
    {
        $minOrders = $data['tier_min_orders'] ?? [];
        $amounts = $data['tier_amounts'] ?? [];

        unset($data['tier_min_orders'], $data['tier_amounts']);

        $tiers = [];

        foreach ($minOrders as $index => $min) {
            $amount = $amounts[$index] ?? null;

            // A half-filled row is a row somebody started and abandoned, not a
            // tier of zero. Dropped rather than saved as one.
            if ($min === null || $min === '' || $amount === null || $amount === '') {
                continue;
            }

            $tiers[(int) $min] = [
                'min_orders' => (int) $min,
                'amount' => round((float) $amount, 2),
            ];
        }

        // Keyed by threshold on the way in, so two rows typed at the same
        // threshold collapse to one instead of hitting the unique key and
        // throwing on save.
        return array_values($tiers);
    }

    /**
     * Replace a rule's tiers with the ones just submitted.
     *
     * Delete-then-insert rather than a per-row diff: the form has no stable id
     * for a row an operator dragged in or deleted, and a tier carries nothing
     * worth preserving — it is a threshold and an amount. The awards already
     * paid are untouched, because an award stores the threshold it was paid at
     * rather than pointing at the tier row.
     *
     * @param  array<int, array{min_orders: int, amount: float}>  $tiers
     */
    private function syncTiers(int $ruleId, array $tiers): void
    {
        DriverBonusTier::where('driver_bonus_rule_id', $ruleId)->delete();

        foreach ($tiers as $tier) {
            DriverBonusTier::create($tier + ['driver_bonus_rule_id' => $ruleId]);
        }
    }

    /**
     * Keep the two amount columns mutually exclusive.
     *
     * A rule that carries both a flat amount and a percentage is a rule with two
     * answers, and whichever the calculator read first would silently become the
     * truth. The basis decides which one survives.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalise(array $data): array
    {
        unset($data['id']);

        $basis = BonusBasis::tryFrom($data['basis'] ?? '');

        if ($basis?->isFlat()) {
            $data['rate'] = null;
        } else {
            $data['amount'] = null;
        }

        $data['name'] = json_encode($data['name'], JSON_UNESCAPED_UNICODE);

        // An empty gate box means «no gate», which is null and not zero: a
        // maximum of zero failed journeys is a real and very strict rule, and
        // blank must not silently become it.
        foreach (['min_on_time_rate', 'min_delivery_rating', 'max_failed_tasks'] as $gate) {
            if (($data[$gate] ?? '') === '') {
                $data[$gate] = null;
            }
        }

        return $data;
    }
}
