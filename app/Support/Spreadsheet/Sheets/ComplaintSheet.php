<?php

namespace App\Support\Spreadsheet\Sheets;

use App\Models\Role;
use App\Modules\Complaint\Enums\ComplaintStatus;
use App\Modules\Complaint\Models\Complaint;
use App\Support\Spreadsheet\Column;
use App\Support\Spreadsheet\Sheet;
use Illuminate\Database\Eloquent\Builder;

/**
 * «الشكاوى» — the complaint queue, export only.
 *
 * Not tenant-scoped, like the screen: complaints are the platform's to handle,
 * and `complaint.*` being the super admin's alone is the protection. With no
 * status at all it holds what the screen opens on — the open complaints. The
 * low ratings the screen lists beneath the queue are the ratings sheet's.
 */
class ComplaintSheet extends Sheet
{
    public function key(): string
    {
        return 'complaint';
    }

    public function title(): string
    {
        return 'complaints';
    }

    public function query(): Builder
    {
        return Complaint::query()->with([
            'complainant:id,name,phone,role_id', 'complainant.role:id,slug',
            'order:id,code', 'laundry:id,name', 'handler:id,name',
        ]);
    }

    public function searchColumns(): array
    {
        // ComplaintController::search().
        return ['reference', 'body', 'category', 'complainant.name', 'complainant.phone', 'order.code', 'laundry.name', 'handler.name'];
    }

    public function filter(Builder $query, array $filters): Builder
    {
        $query = parent::filter($query, $filters);

        // ComplaintController::listing(): no status at all opens on `open`;
        // `all`, or anything that is not a status, is every row.
        $status = array_key_exists('status', $filters) ? $filters['status'] : 'open';

        if ($status === 'open') {
            $query->scopes('open');
        } elseif (in_array($status, ComplaintStatus::values(), true)) {
            $query->where($query->qualifyColumn('status'), $status);
        }

        // Filed by a customer or by a driver — the complainant's role, not the
        // category, since both can file `late`.
        $audience = $filters['audience'] ?? null;

        if (in_array($audience, ['customer', 'driver'], true)) {
            $slug = $audience === 'driver' ? Role::DRIVER : Role::USER;
            $query->whereHas('complainant.role', fn (Builder $role) => $role->where('slug', $slug));
        }

        return $query;
    }

    public function columns(): array
    {
        return [
            Column::make('id'),
            Column::make('reference'),
            Column::make('created_at'),
            Column::make('status'),
            Column::make('category'),
            Column::make('user_id'),
            Column::readOnly('complainant', fn (Complaint $complaint) => $complaint->complainant?->name),
            Column::readOnly('complainant_phone', fn (Complaint $complaint) => $complaint->complainant?->phone),
            Column::readOnly('complainant_role', fn (Complaint $complaint) => $complaint->complainant?->role?->slug),
            Column::make('order_id'),
            Column::readOnly('order_code', fn (Complaint $complaint) => $complaint->order?->code),
            Column::make('laundry_id'),
            Column::readOnly('laundry', fn (Complaint $complaint) => $complaint->laundry ? getLocalizedValueDashboard($complaint->laundry, 'name') : null),
            Column::make('body'),
            Column::make('internal_note'),
            Column::make('handled_by'),
            Column::readOnly('handler', fn (Complaint $complaint) => $complaint->handler?->name),
            Column::make('handled_at'),
        ];
    }
}
