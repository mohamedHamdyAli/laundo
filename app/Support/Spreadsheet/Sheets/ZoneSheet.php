<?php

namespace App\Support\Spreadsheet\Sheets;

use App\Modules\Zone\Models\Zone;
use App\Modules\Zone\Requests\ZoneRequest;
use App\Modules\Zone\Services\zoneCrudService;
use App\Support\Spreadsheet\Column;
use App\Support\Spreadsheet\Sheet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Zones: every field of the form but the drawing. The drawing is a ring of
 * corners drawn on the map (ZoneLocator) and is edited there, not in a cell —
 * an import never touches it, since the crud service only writes `boundary`
 * when it is sent. `drawn` says which zones still go by the customer's pick
 * from a list, for whoever is planning which ones to draw next.
 */
class ZoneSheet extends Sheet
{
    public function key(): string
    {
        return 'zone';
    }

    public function title(): string
    {
        return 'zones';
    }

    public function query(): Builder
    {
        return Zone::query()->with('city:id,name');
    }

    public function searchColumns(): array
    {
        return ['name', 'city.name', 'sort_order'];
    }

    public function columns(): array
    {
        return [
            Column::make('id'),
            Column::make('name')->translatable(),
            Column::make('city_id'),
            Column::readOnly('city', fn (Zone $zone) => $zone->city ? getLocalizedValueDashboard($zone->city, 'name') : null),
            Column::make('price_per_km'),
            Column::make('min_delivery_fee'),
            Column::make('sort_order'),
            Column::make('status'),
            Column::readOnly('drawn', fn (Zone $zone) => $zone->isDrawn() ? __('Yes') : __('No')),
        ];
    }

    public function importable(): bool
    {
        return true;
    }

    public function request(): string
    {
        return ZoneRequest::class;
    }

    public function create(array $validated): void
    {
        app(zoneCrudService::class)->addNew($validated);
    }

    public function update(Model $row, array $validated): void
    {
        app(zoneCrudService::class)->updateRecord($validated + ['id' => $row->getKey()]);
    }
}
