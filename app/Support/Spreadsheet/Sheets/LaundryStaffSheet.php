<?php

namespace App\Support\Spreadsheet\Sheets;

use App\Modules\LaundryStaff\Models\LaundryStaff;
use App\Support\Spreadsheet\Column;
use App\Support\Spreadsheet\Sheet;
use Illuminate\Database\Eloquent\Builder;

/**
 * «موظفو المغاسل» — export only.
 *
 * Tenant-scoped by the model itself (BelongsToLaundry), so a laundry owner's
 * export holds its own staff and nobody else's. They are user accounts: the
 * password, the remember token and the OTP columns are never written.
 */
class LaundryStaffSheet extends Sheet
{
    public function key(): string
    {
        return 'laundry_staff';
    }

    public function title(): string
    {
        return 'laundry-staff';
    }

    public function query(): Builder
    {
        return LaundryStaff::query()->with(['role', 'laundry']);
    }

    public function searchColumns(): array
    {
        return ['name', 'phone', 'email', 'laundry.name', 'role.name'];
    }

    public function columns(): array
    {
        return [
            Column::make('id'),
            Column::make('name'),
            Column::make('phone'),
            Column::make('email'),
            Column::make('laundry_id'),
            Column::readOnly('laundry', fn (LaundryStaff $staff) => $staff->laundry ? getLocalizedValueDashboard($staff->laundry, 'name') : null),
            Column::make('role_id'),
            Column::readOnly('role', fn (LaundryStaff $staff) => $staff->role?->name),
            Column::make('status'),
            Column::make('created_at'),
        ];
    }
}
