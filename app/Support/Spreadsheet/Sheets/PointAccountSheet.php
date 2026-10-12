<?php

namespace App\Support\Spreadsheet\Sheets;

use App\Modules\Loyalty\Models\PointAccount;
use App\Modules\Loyalty\Repositories\PointAccountRepository;
use App\Support\LaundryContext;
use App\Support\Spreadsheet\Column;
use App\Support\Spreadsheet\Sheet;
use Illuminate\Database\Eloquent\Builder;

/**
 * «نقاط العملاء» — every customer's points, export only. A balance is never
 * edited directly, and a spreadsheet is no exception.
 *
 * Empty inside a laundry, whatever it was granted: the accounts are every
 * customer on the platform and not tenant-scoped — the screen refuses the same
 * way (`pointAccountCrudService`).
 */
class PointAccountSheet extends Sheet
{
    public function key(): string
    {
        return 'point_account';
    }

    public function title(): string
    {
        return 'customer_points';
    }

    public function query(): Builder
    {
        return PointAccount::query()
            ->withLedger()
            ->with('owner:id,name,phone,email,customer_reference')
            ->when(LaundryContext::currentId() !== null, fn (Builder $q) => $q->whereRaw('1 = 0'));
    }

    public function searchColumns(): array
    {
        return PointAccountRepository::SEARCH;
    }

    public function columns(): array
    {
        return [
            Column::make('id'),
            Column::make('user_id'),
            Column::readOnly('customer', fn (PointAccount $account) => $account->owner?->name),
            Column::readOnly('customer_phone', fn (PointAccount $account) => $account->owner?->phone),
            Column::readOnly('customer_reference', fn (PointAccount $account) => $account->owner?->customer_reference),
            Column::make('balance'),
            Column::readOnly('ledger_balance', fn (PointAccount $account) => $account->ledgerBalance()),
            Column::readOnly('reconciled', fn (PointAccount $account) => $account->isReconciled()),
            Column::make('created_at'),
        ];
    }
}
