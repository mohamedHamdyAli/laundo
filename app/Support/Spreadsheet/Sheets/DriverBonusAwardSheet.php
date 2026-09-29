<?php

namespace App\Support\Spreadsheet\Sheets;

use App\Modules\Driver\Models\DriverBonusAward;
use App\Modules\Driver\Services\MonthlyBonusService;
use App\Support\Spreadsheet\Column;
use App\Support\Spreadsheet\Sheet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * «مكافآت الشهر» — one month's driver bonuses, export only. Approving is a
 * person's act on the screen, never a row in a file.
 *
 * Always one month, like the screen: the `period` it is showing, or this month
 * when none is given. The export does not recompute an open month — opening the
 * screen already did, and a download is not the place to write.
 */
class DriverBonusAwardSheet extends Sheet
{
    public function key(): string
    {
        return 'driver_bonus_award';
    }

    public function title(): string
    {
        return 'driver-bonus-awards';
    }

    public function query(): Builder
    {
        return DriverBonusAward::query()->with(['driver:id,name,phone', 'rule:id,name']);
    }

    public function searchColumns(): array
    {
        // DriverBonusAwardController::search().
        return ['driver.name', 'driver.phone'];
    }

    public function filter(Builder $query, array $filters): Builder
    {
        $query = parent::filter($query, $filters);

        // Parsed through the service, as the screen does: the period arrives
        // off a query string and is refused unless it is a real month. Resolved
        // here rather than injected, so discovering the sheet costs nothing.
        $bonuses = app(MonthlyBonusService::class);
        $month = $bonuses->parsePeriod(is_string($filters['period'] ?? null) ? $filters['period'] : null)
            ?? CarbonImmutable::now()->startOfMonth();

        $query->where($query->qualifyColumn('period'), $bonuses->period($month));

        $status = $filters['status'] ?? null;

        if (in_array($status, [DriverBonusAward::DUE, DriverBonusAward::APPROVED, DriverBonusAward::REJECTED], true)) {
            $query->where($query->qualifyColumn('status'), $status);
        }

        return $query;
    }

    public function columns(): array
    {
        return [
            Column::make('id'),
            Column::make('period'),
            Column::make('driver_id'),
            Column::readOnly('driver', fn (DriverBonusAward $award) => $award->driver?->name),
            Column::readOnly('driver_phone', fn (DriverBonusAward $award) => $award->driver?->phone),
            Column::make('driver_bonus_rule_id'),
            Column::readOnly('rule', fn (DriverBonusAward $award) => $award->rule ? getLocalizedValueDashboard($award->rule, 'name') : null),
            Column::make('orders_count'),
            Column::make('tier_min_orders'),
            Column::make('on_time_rate'),
            Column::make('avg_delivery_rating'),
            Column::make('failed_tasks'),
            Column::make('gate_failures')->value(fn (DriverBonusAward $award) => $award->gate_failures ? implode(', ', $award->gate_failures) : null),
            Column::make('amount'),
            Column::make('status'),
            Column::make('approved_at'),
            Column::make('approved_by'),
            Column::make('note'),
        ];
    }
}
