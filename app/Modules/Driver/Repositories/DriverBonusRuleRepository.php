<?php

namespace App\Modules\Driver\Repositories;

use App\Modules\Driver\Models\DriverBonusRule;

/**
 * The only place raw Eloquent for bonus rules lives.
 */
class DriverBonusRuleRepository
{
    public function getAll($perPage = 10)
    {
        // `tiers` and the driver count eagerly: the list shows both on every
        // row, and without them a page of ten rules is twenty extra queries.
        return DriverBonusRule::with('tiers')
            ->withCount('profiles')
            ->latest('id')
            ->paginate($perPage);
    }

    public function search($query, $perPage = 10)
    {
        return DriverBonusRule::with('tiers')
            ->withCount('profiles')
            ->search($query, ['name'])
            ->latest('id')
            ->paginate($perPage);
    }

    public function find($id)
    {
        return DriverBonusRule::with('tiers')->withCount('profiles')->findOrFail($id);
    }

    public function create(array $data)
    {
        return DriverBonusRule::create($data);
    }

    public function update($id, array $data)
    {
        $rule = DriverBonusRule::findOrFail($id);
        $rule->update($data);

        return $rule;
    }

    public function delete($id)
    {
        $rule = DriverBonusRule::findOrFail($id);

        // The tiers go with it by cascade; the drivers do not — their
        // `bonus_rule_id` is nullOnDelete, so they fall back to no bonus rather
        // than being deleted alongside their terms.
        $rule->delete();

        return true;
    }
}
