<?php

namespace App\Support\Spreadsheet\Sheets;

use App\Modules\City\Models\City;
use App\Modules\City\Requests\CityRequest;
use App\Modules\City\Services\cityCrudService;
use App\Support\Spreadsheet\Column;
use App\Support\Spreadsheet\Sheet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CitySheet extends Sheet
{
    public function key(): string
    {
        return 'city';
    }

    public function title(): string
    {
        return 'cities';
    }

    public function query(): Builder
    {
        return City::query()->with('country:id,name');
    }

    public function searchColumns(): array
    {
        return ['name', 'country.name'];
    }

    public function columns(): array
    {
        return [
            Column::make('id'),
            Column::make('name')->translatable(),
            Column::make('country_id'),
            Column::readOnly('country', fn (City $city) => $city->country ? getLocalizedValueDashboard($city->country, 'name') : null),
            Column::make('lat'),
            Column::make('lng'),
            Column::make('status'),
        ];
    }

    public function importable(): bool
    {
        return true;
    }

    public function request(): string
    {
        return CityRequest::class;
    }

    public function create(array $validated): void
    {
        app(cityCrudService::class)->addNew($validated);
    }

    public function update(Model $row, array $validated): void
    {
        app(cityCrudService::class)->updateRecord($validated + ['id' => $row->getKey()]);
    }
}
