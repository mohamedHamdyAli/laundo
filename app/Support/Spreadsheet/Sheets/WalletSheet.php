<?php

namespace App\Support\Spreadsheet\Sheets;

use App\Modules\Wallet\Controllers\WalletController;
use App\Modules\Wallet\Enums\WalletOwnerType;
use App\Modules\Wallet\Models\Wallet;
use App\Support\Spreadsheet\Column;
use App\Support\Spreadsheet\Sheet;
use Illuminate\Database\Eloquent\Builder;

/**
 * «المحافظ» — every wallet, export only. A balance is never edited directly,
 * and a spreadsheet is no exception.
 *
 * The `type` filter has the screen's three states: a group (every wallet in
 * it, empty ones included), `all` (every wallet), and nothing (the wallets
 * holding money — what the screen opens on). The ledger balance is exported
 * beside the cached one, because proving the two agree is what the screen is
 * for.
 */
class WalletSheet extends Sheet
{
    /** @var array{0: int, 1: float}|null the last wallet's ledger, read once for two columns */
    private ?array $ledger = null;

    public function key(): string
    {
        return 'wallet';
    }

    public function title(): string
    {
        return 'wallets';
    }

    public function query(): Builder
    {
        return Wallet::query()->with('owner:id,name,phone,email,role_id', 'owner.role:id,slug,name');
    }

    public function searchColumns(): array
    {
        // WalletController::search().
        return ['owner.name', 'owner.phone', 'owner.email'];
    }

    public function filter(Builder $query, array $filters): Builder
    {
        $query = parent::filter($query, $filters);

        // The same three states as WalletController::query().
        $raw = is_string($filters['type'] ?? null) ? $filters['type'] : '';
        $type = WalletOwnerType::tryFrom($raw);
        $every = $raw === WalletController::EVERY;

        if ($type !== null) {
            $query->whereHas('owner.role', fn (Builder $role) => $role->whereIn('slug', $type->roleSlugs()));
        } elseif (! $every) {
            $query->where(fn (Builder $inner) => $inner->where('balance', '>', 0)->orWhere('pending_balance', '>', 0));
        }

        return $query;
    }

    public function columns(): array
    {
        return [
            Column::make('id'),
            Column::make('user_id'),
            Column::readOnly('owner', fn (Wallet $wallet) => $wallet->owner?->name),
            Column::readOnly('owner_phone', fn (Wallet $wallet) => $wallet->owner?->phone),
            Column::readOnly('owner_email', fn (Wallet $wallet) => $wallet->owner?->email),
            // The group the screen's pill names, or the raw role slug when no
            // group covers it — the same fallback the list uses.
            Column::readOnly('owner_type', fn (Wallet $wallet) => WalletOwnerType::forRoleSlug($wallet->owner?->role?->slug)->value
                ?? $wallet->owner?->role?->slug),
            Column::make('currency'),
            Column::make('balance'),
            Column::make('pending_balance'),
            Column::readOnly('ledger_balance', fn (Wallet $wallet) => $this->ledgerOf($wallet)),
            Column::readOnly('reconciled', fn (Wallet $wallet) => abs($this->ledgerOf($wallet) - (float) $wallet->balance) < 0.005),
            Column::make('is_frozen'),
            Column::make('created_at'),
        ];
    }

    /**
     * `Wallet::ledgerBalance()`, read once per row rather than once per column —
     * it is two sums over the wallet's transactions.
     */
    private function ledgerOf(Wallet $wallet): float
    {
        if ($this->ledger === null || $this->ledger[0] !== $wallet->id) {
            $this->ledger = [$wallet->id, $wallet->ledgerBalance()];
        }

        return $this->ledger[1];
    }
}
