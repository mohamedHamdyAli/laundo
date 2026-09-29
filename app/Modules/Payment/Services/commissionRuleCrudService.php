<?php

namespace App\Modules\Payment\Services;

use App\Modules\Laundry\Models\Laundry;
use App\Modules\Payment\Enums\CommissionBasis;
use App\Modules\Payment\Repositories\CommissionRuleRepository;
use Illuminate\Support\Facades\DB;

/**
 * Business rules for «قواعد العمولة» — each rule is the share of the washing a
 * laundry receives.
 *
 * Two rules carry weight: a rule is a percentage and nothing else (`normalise()`),
 * and a laundry is on one active share at a time (`activationRefusal()`, and the
 * request for the form).
 */
class commissionRuleCrudService
{
    public function __construct(private readonly CommissionRuleRepository $rules) {}

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
        $data = [
            // Unscoped: this screen is the super admin's and the picker has to
            // offer every laundry, not the one the viewer belongs to.
            'laundries' => Laundry::withoutGlobalScopes()->orderBy('id')->get(['id', 'name']),
        ];

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
            $laundryIds = $this->pullLaundries($data);
            $rule = $this->rules->create($this->normalise($data));

            $rule->laundries()->sync($laundryIds);

            return $rule;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateRule(array $data)
    {
        return DB::transaction(function () use ($data) {
            $laundryIds = $this->pullLaundries($data);
            $rule = $this->rules->update($data['id'], $this->normalise($data));

            // sync(), so a laundry the operator unticked stops being charged.
            // Attaching would only ever add, and a charge nobody can remove is
            // the worst kind.
            $rule->laundries()->sync($laundryIds);

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
     * Why this rule cannot be switched on, or null when it can.
     *
     * The toggle is the other door to two active shares on one laundry: the form
     * refuses a laundry already on another share, but switching an old rule
     * back on would put every laundry still attached to it on two at once, and
     * the settlement would silently pick one.
     *
     * A fixed rule is history — the fixed basis went when the percentage moved
     * to the laundry's side — and switching it on would be terms nothing reads.
     */
    public function activationRefusal($id): ?string
    {
        $rule = $this->rules->find($id);

        if ($rule->basis->isFixed()) {
            return __('A fixed-amount rule is retired and cannot be switched on. Create a percentage share instead.');
        }

        $clashes = $this->rules->laundriesOnAnotherShare($rule->laundries->pluck('id')->all(), (int) $rule->id);

        if ($clashes->isEmpty()) {
            return null;
        }

        return __('These laundries are already on another active share: :names', [
            'names' => $clashes->map(fn ($laundry) => getLocalizedValueDashboard($laundry, 'name'))->implode('، '),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, int>
     */
    private function pullLaundries(array &$data): array
    {
        $ids = $data['laundry_ids'] ?? [];
        unset($data['laundry_ids']);

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * A rule is a percentage of the washing, and only that.
     *
     * The fixed basis was retired with the reversal, so whatever the form sent,
     * the rule is written as a percentage and the amount column is cleared —
     * a rule carrying a stale flat amount is a rule with two answers, and
     * whichever something read first would silently become the truth.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalise(array $data): array
    {
        unset($data['id']);

        $data['basis'] = CommissionBasis::Percent->value;
        $data['amount'] = null;

        $data['name'] = json_encode($data['name'], JSON_UNESCAPED_UNICODE);

        return $data;
    }
}
