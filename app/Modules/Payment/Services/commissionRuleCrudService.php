<?php

namespace App\Modules\Payment\Services;

use App\Modules\Laundry\Models\Laundry;
use App\Modules\Payment\Enums\CommissionBasis;
use App\Modules\Payment\Repositories\CommissionRuleRepository;
use Illuminate\Support\Facades\DB;

/**
 * Business rules for «قواعد العمولة».
 *
 * The only rule with any weight in it is `normalise()`: the two value columns
 * are mutually exclusive, and the basis decides which survives.
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
            'bases' => CommissionBasis::cases(),
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
     * Keep the two value columns mutually exclusive.
     *
     * A rule carrying both a percentage and a flat amount is a rule with two
     * answers, and whichever the calculator read first would silently become the
     * truth. The basis decides which one survives.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalise(array $data): array
    {
        unset($data['id']);

        $basis = CommissionBasis::tryFrom($data['basis'] ?? '');

        if ($basis?->isFixed()) {
            $data['rate'] = null;
        } else {
            $data['amount'] = null;
        }

        $data['name'] = json_encode($data['name'], JSON_UNESCAPED_UNICODE);

        return $data;
    }
}
