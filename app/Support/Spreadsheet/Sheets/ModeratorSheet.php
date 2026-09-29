<?php

namespace App\Support\Spreadsheet\Sheets;

use App\Modules\Moderator\Models\Moderator;
use App\Support\Spreadsheet\Column;
use App\Support\Spreadsheet\Sheet;
use Illuminate\Database\Eloquent\Builder;

/**
 * Moderators — export only. They are user accounts: the password, the
 * remember token and the OTP columns are never written.
 */
class ModeratorSheet extends Sheet
{
    public function key(): string
    {
        return 'moderator';
    }

    public function title(): string
    {
        return 'moderators';
    }

    public function query(): Builder
    {
        return Moderator::query()->with('role');
    }

    public function searchColumns(): array
    {
        return ['name', 'phone', 'email', 'role.name'];
    }

    public function columns(): array
    {
        return [
            Column::make('id'),
            Column::make('name'),
            Column::make('phone'),
            Column::make('email'),
            Column::make('role_id'),
            Column::readOnly('role', fn (Moderator $moderator) => $moderator->role?->name),
            Column::make('status'),
            Column::make('created_at'),
        ];
    }
}
