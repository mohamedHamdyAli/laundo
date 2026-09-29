<?php

namespace App\Support\Spreadsheet\Sheets;

use App\Modules\Driver\Models\DriverApplication;
use App\Support\Spreadsheet\Column;
use App\Support\Spreadsheet\Sheet;
use Illuminate\Database\Eloquent\Builder;

/**
 * Driver leads from the public form — export only. A lead is somebody who
 * filled in a form, so nothing here is ever authored by a spreadsheet.
 */
class DriverApplicationSheet extends Sheet
{
    public function key(): string
    {
        return 'driver_application';
    }

    public function title(): string
    {
        return 'driver-applications';
    }

    public function query(): Builder
    {
        return DriverApplication::query()->with('handler:id,name');
    }

    public function searchColumns(): array
    {
        return ['name', 'phone', 'note'];
    }

    public function columns(): array
    {
        return [
            Column::make('id'),
            Column::make('name'),
            Column::make('phone'),
            Column::make('note'),
            Column::make('created_at'),
            Column::readOnly('state', fn (DriverApplication $application) => $application->isWaiting() ? 'waiting' : 'handled'),
            Column::make('handled_at'),
            Column::make('handled_by'),
            Column::readOnly('handler', fn (DriverApplication $application) => $application->handler?->name),
            Column::make('admin_note'),
        ];
    }
}
