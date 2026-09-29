<?php

namespace App\Support\Spreadsheet\Sheets;

use App\Modules\Country\Models\Country;
use App\Modules\Country\Requests\CountryRequest;
use App\Modules\Country\Services\countryCrudService;
use App\Support\Spreadsheet\Column;
use App\Support\Spreadsheet\Sheet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CountrySheet extends Sheet
{
    public function key(): string
    {
        return 'country';
    }

    public function title(): string
    {
        return 'countries';
    }

    public function query(): Builder
    {
        return Country::query();
    }

    public function searchColumns(): array
    {
        return ['name', 'code', 'phone_code'];
    }

    public function columns(): array
    {
        return [
            Column::make('id'),
            Column::make('name')->translatable(),
            Column::make('code'),
            Column::make('phone_code'),
            Column::make('timezone'),
            Column::make('status'),
        ];
    }

    public function importable(): bool
    {
        return true;
    }

    public function request(): string
    {
        return CountryRequest::class;
    }

    public function input(array $cells, ?Model $existing = null): array
    {
        $input = parent::input($cells, $existing);

        // Excel turns a typed `+20` into the number 20, which the form's
        // `string` rule would refuse for a type rather than for its content.
        if (isset($input['phone_code'])) {
            $input['phone_code'] = (string) $input['phone_code'];
        }

        return $input;
    }

    public function create(array $validated): void
    {
        app(countryCrudService::class)->addNew($validated);
    }

    public function update(Model $row, array $validated): void
    {
        // The repository re-derives a blank timezone from the code. The form
        // always posts the stored one, so a blank cell keeps it the same way
        // rather than replacing a hand-set zone with the code's default.
        if (! isset($validated['timezone']) && $row->getAttribute('timezone')) {
            $validated['timezone'] = $row->getAttribute('timezone');
        }

        app(countryCrudService::class)->updateRecord($validated + ['id' => $row->getKey()]);
    }
}
