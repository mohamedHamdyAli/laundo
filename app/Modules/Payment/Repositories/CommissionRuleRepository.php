<?php

namespace App\Modules\Payment\Repositories;

use App\Modules\Payment\Models\CommissionRule;

/**
 * The only place raw Eloquent for commission rules lives.
 */
class CommissionRuleRepository
{
    public function getAll($perPage = 10)
    {
        // The laundry count eagerly: the list shows it on every row, and it is
        // the figure that says whether editing these terms is a small act or a
        // change to forty contracts.
        return CommissionRule::withCount('laundries')
            ->latest('id')
            ->paginate($perPage);
    }

    public function search($query, $perPage = 10)
    {
        return CommissionRule::withCount('laundries')
            ->search($query, ['name'])
            ->latest('id')
            ->paginate($perPage);
    }

    public function find($id)
    {
        return CommissionRule::with('laundries:id,name')->withCount('laundries')->findOrFail($id);
    }

    public function create(array $data)
    {
        return CommissionRule::create($data);
    }

    public function update($id, array $data)
    {
        $rule = CommissionRule::findOrFail($id);
        $rule->update($data);

        return $rule;
    }

    public function delete($id)
    {
        $rule = CommissionRule::findOrFail($id);

        // The pivot rows go by cascade, so the laundries simply stop being
        // charged under it. Settlement lines do NOT go: their
        // `commission_rule_id` is nullOnDelete and the name and terms are copied
        // onto the line, so what a laundry was already charged stays readable.
        $rule->delete();

        return true;
    }
}
