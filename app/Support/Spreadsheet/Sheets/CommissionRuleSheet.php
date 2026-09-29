<?php

namespace App\Support\Spreadsheet\Sheets;

use App\Modules\Payment\Models\CommissionRule;
use App\Support\Spreadsheet\Column;
use App\Support\Spreadsheet\Sheet;
use Illuminate\Database\Eloquent\Builder;

/**
 * The laundry shares — export only. A money term typed into a spreadsheet is
 * not a term anybody agreed to, so this sheet never imports.
 */
class CommissionRuleSheet extends Sheet
{
    public function key(): string
    {
        return 'commission_rule';
    }

    public function title(): string
    {
        return 'commissions';
    }

    public function query(): Builder
    {
        // The laundry count, as the list shows it on every row.
        return CommissionRule::query()->withCount('laundries');
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
            Column::make('rate'),
            Column::make('amount'),
            Column::readOnly('terms', fn (CommissionRule $rule) => $rule->explain()),
            Column::readOnly('laundries_count', fn (CommissionRule $rule) => (int) $rule->laundries_count),
            Column::make('status'),
        ];
    }
}
