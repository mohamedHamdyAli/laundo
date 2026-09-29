<?php

namespace App\Support\Spreadsheet\Sheets;

use App\Modules\Service\Models\Service;
use App\Modules\Service\Repositories\ServiceRepository;
use App\Modules\Service\Requests\ServiceRequest;
use App\Modules\Service\Services\serviceCrudService;
use App\Support\Spreadsheet\Column;
use App\Support\Spreadsheet\Sheet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The image is left out: a file cannot travel in a cell, and the form does not
 * require one. Switching `pricing_mode` to `quote` here drops the service's
 * grid prices, exactly as the form does — the service's own update runs.
 */
class ServiceSheet extends Sheet
{
    public function key(): string
    {
        return 'service';
    }

    public function title(): string
    {
        return 'services';
    }

    public function query(): Builder
    {
        return Service::query();
    }

    /**
     * The list's own search — it rebuilds the duration cell and strips the unit
     * word, which a plain column list cannot — so the two never disagree.
     */
    public function filter(Builder $query, array $filters): Builder
    {
        $term = trim((string) ($filters['query'] ?? ''));

        return $term === '' ? $query : app(ServiceRepository::class)->applySearch($query, $term);
    }

    public function columns(): array
    {
        return [
            Column::make('id'),
            Column::make('name')->translatable(),
            Column::make('description')->translatable(),
            Column::make('pricing_mode'),
            Column::make('duration_min'),
            Column::make('duration_max'),
            Column::make('duration_unit'),
            Column::make('sort_order'),
            Column::make('status'),
        ];
    }

    public function importable(): bool
    {
        return true;
    }

    public function request(): string
    {
        return ServiceRequest::class;
    }

    public function input(array $cells, ?Model $existing = null): array
    {
        $input = parent::input($cells, $existing);

        // The form checks the turnaround as a pair (`duration_max` gte
        // `duration_min`) and always posts both. A row touching one bound is
        // checked against the stored other one, not against nothing — which
        // would refuse a valid range, or let a backwards one through.
        if ($existing && (isset($input['duration_min']) || isset($input['duration_max']))) {
            foreach (['duration_min', 'duration_max'] as $bound) {
                if (! isset($input[$bound]) && $existing->getAttribute($bound) !== null) {
                    $input[$bound] = $existing->getAttribute($bound);
                }
            }
        }

        return $input;
    }

    public function create(array $validated): void
    {
        app(serviceCrudService::class)->addNew($validated);
    }

    public function update(Model $row, array $validated): void
    {
        app(serviceCrudService::class)->updateRecord($validated + ['id' => $row->getKey()]);
    }
}
