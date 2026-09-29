<?php

namespace App\Support\Spreadsheet\Sheets;

use App\Modules\Driver\Models\DriverBonusRule;
use App\Modules\Driver\Models\DriverBonusTier;
use App\Support\Spreadsheet\Column;
use App\Support\Spreadsheet\Sheet;
use Illuminate\Database\Eloquent\Builder;

/**
 * Driver bonus rules — export only. What a driver is paid is not set from a
 * spreadsheet, so this sheet never imports.
 */
class DriverBonusRuleSheet extends Sheet
{
    public function key(): string
    {
        return 'driver_bonus_rule';
    }

    public function title(): string
    {
        return 'driver-bonus-rules';
    }

    public function query(): Builder
    {
        // Tiers and the driver count, as the list shows both on every row.
        return DriverBonusRule::query()->with('tiers')->withCount('profiles');
    }

    public function searchColumns(): array
    {
        return ['name'];
    }

    public function columns(): array
    {
        return [
            Column::make('id'),
            Column::make('name')->translatable(),
            Column::make('basis'),
            Column::make('amount'),
            Column::make('rate'),
            // One cell for the ladder: «10 → 50.00; 25 → 150.00» — orders reached, then the award.
            Column::readOnly('monthly_tiers', fn (DriverBonusRule $rule) => $rule->tiers
                ->map(fn (DriverBonusTier $tier) => $tier->min_orders.' → '.$tier->amount)
                ->implode('; ') ?: null),
            Column::make('min_on_time_rate'),
            Column::make('min_delivery_rating'),
            Column::make('max_failed_tasks'),
            Column::readOnly('drivers_count', fn (DriverBonusRule $rule) => (int) $rule->profiles_count),
            Column::make('status'),
        ];
    }
}
