<?php

namespace App\Support\Spreadsheet\Sheets;

use App\Modules\LaundryService\Models\LaundryServiceRequest;
use App\Support\Spreadsheet\Column;
use App\Support\Spreadsheet\Sheet;
use Illuminate\Database\Eloquent\Builder;

/**
 * Laundries asking to open or close a service — export only; a request is
 * something a laundry makes, never something a spreadsheet does.
 *
 * Tenant-scoped by the model itself (BelongsToLaundry).
 */
class LaundryServiceRequestSheet extends Sheet
{
    public function key(): string
    {
        return 'laundry_service_request';
    }

    public function title(): string
    {
        return 'service-requests';
    }

    public function query(): Builder
    {
        return LaundryServiceRequest::query()
            ->with(['laundry:id,name', 'service:id,name', 'requester:id,name', 'reviewer:id,name']);
    }

    public function searchColumns(): array
    {
        return ['laundry.name', 'service.name', 'action', 'status', 'note'];
    }

    /**
     * The status dropdown, exactly as `LaundryServiceRequestController::listing()`
     * reads it: absent means pending — the screen opens there — and anything that
     * is not a decision («all») means every row but the superseded, which are the
     * history of a question that was asked again.
     */
    public function filter(Builder $query, array $filters): Builder
    {
        $status = is_string($filters['status'] ?? null) ? $filters['status'] : LaundryServiceRequest::PENDING;

        $decisions = [LaundryServiceRequest::PENDING, LaundryServiceRequest::APPROVED, LaundryServiceRequest::REJECTED];

        $query->when(
            in_array($status, $decisions, true),
            fn (Builder $q) => $q->where('status', $status),
            fn (Builder $q) => $q->where('status', '<>', LaundryServiceRequest::SUPERSEDED)
        );

        return parent::filter($query, $filters);
    }

    public function columns(): array
    {
        return [
            Column::make('id'),
            Column::make('laundry_id'),
            Column::readOnly('laundry', fn (LaundryServiceRequest $row) => $row->laundry ? getLocalizedValueDashboard($row->laundry, 'name') : null),
            Column::make('service_id'),
            Column::readOnly('service', fn (LaundryServiceRequest $row) => $row->service ? getLocalizedValueDashboard($row->service, 'name') : null),
            Column::make('action'),
            Column::make('status'),
            Column::make('created_at'),
            Column::make('requested_by'),
            Column::readOnly('requester', fn (LaundryServiceRequest $row) => $row->requester?->name),
            Column::make('reviewed_at'),
            Column::make('reviewed_by'),
            Column::readOnly('reviewer', fn (LaundryServiceRequest $row) => $row->reviewer?->name),
            Column::make('note'),
        ];
    }
}
