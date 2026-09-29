<?php

namespace App\Support\Spreadsheet\Sheets;

use App\Models\Role;
use App\Modules\User\Models\User;
use App\Support\Spreadsheet\Column;
use App\Support\Spreadsheet\Sheet;
use Illuminate\Database\Eloquent\Builder;

/**
 * «العملاء» — the customers list, `admin.user.*`.
 *
 * **Export only.** The screen's own form, `UserRequest`, requires a profile
 * photograph to add a customer, and a photograph is a file a spreadsheet cell
 * cannot carry — so a sheet could only ever change existing rows, and a sheet
 * that imports half of what its template offers is worse than one that says
 * plainly it does not import.
 *
 * Customers only: the same role filter as `User::availableUsers()`, written out
 * here because that scope also orders by `created_at`, and the exporter walks
 * the query by id.
 *
 * No password, token or verification code is a column.
 */
class UserSheet extends Sheet
{
    public function key(): string
    {
        return 'user';
    }

    public function title(): string
    {
        return 'customers';
    }

    public function query(): Builder
    {
        return User::query()->whereHas('role', fn ($q) => $q->where('slug', Role::USER));
    }

    public function searchColumns(): array
    {
        return ['name', 'phone', 'customer_reference'];
    }

    public function columns(): array
    {
        return [
            Column::make('id'),
            Column::make('name'),
            Column::make('phone'),
            Column::make('email'),
            // The number printed on the customer's bags.
            Column::make('customer_reference'),
            Column::make('status'),
            // Rendered in the display timezone, as the list shows it.
            Column::make('created_at')->value(
                fn (User $user) => $user->created_at?->copy()->setTimezone(displayTimezone())->format('Y-m-d H:i')
            ),
        ];
    }
}
