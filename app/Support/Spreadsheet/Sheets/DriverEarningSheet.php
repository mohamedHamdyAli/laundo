<?php

namespace App\Support\Spreadsheet\Sheets;

use App\Modules\Payment\Models\DriverEarning;
use App\Support\Spreadsheet\Column;
use App\Support\Spreadsheet\Sheet;
use Illuminate\Database\Eloquent\Builder;

/**
 * «أرباح السائقين» — per-leg driver earnings, export only.
 *
 * The screen is `admin.earning.*` and the permission `driver_earning`, which is
 * the key here. Not tenant-scoped, like the screen. With no status at all it
 * holds what the screen opens on — the earnings still held.
 */
class DriverEarningSheet extends Sheet
{
    public function key(): string
    {
        return 'driver_earning';
    }

    public function title(): string
    {
        return 'driver-earnings';
    }

    public function query(): Builder
    {
        // `payee`, not `driver`: the driver relation is role-scoped, and a person
        // moved off driving would drop out of their own history.
        return DriverEarning::query()->with(['payee:id,name,phone', 'order:id,code', 'task:id,type']);
    }

    public function searchColumns(): array
    {
        // PaymentLedgerController::searchEarnings().
        return ['payee.name', 'payee.phone', 'order.code'];
    }

    public function filter(Builder $query, array $filters): Builder
    {
        $query = parent::filter($query, $filters);

        // PaymentLedgerController::earnings(): no status at all opens on
        // `pending`; `all`, or anything that is not a status, is every row.
        $status = array_key_exists('status', $filters) ? $filters['status'] : DriverEarning::PENDING;

        if (in_array($status, [DriverEarning::PENDING, DriverEarning::RELEASED, DriverEarning::CANCELLED], true)) {
            $query->where($query->qualifyColumn('status'), $status);
        }

        return $query;
    }

    public function columns(): array
    {
        return [
            Column::make('id'),
            Column::make('created_at'),
            Column::make('driver_id'),
            Column::readOnly('driver', fn (DriverEarning $earning) => $earning->payee?->name),
            Column::readOnly('driver_phone', fn (DriverEarning $earning) => $earning->payee?->phone),
            Column::make('order_id'),
            Column::readOnly('order_code', fn (DriverEarning $earning) => $earning->order?->code),
            Column::make('order_task_id'),
            Column::readOnly('task_type', fn (DriverEarning $earning) => $earning->task?->type),
            Column::make('basis'),
            Column::make('rate'),
            Column::make('amount'),
            Column::make('status'),
            Column::make('released_at'),
        ];
    }
}
