<?php

namespace App\Modules\Wallet\Models;

use App\Modules\User\Models\User;
use App\Trait\DashboardModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user's money.
 *
 * `balance` is a cache. The truth is the sum of `transactions`, and `reconcile()`
 * is what proves the two agree — a wallet that cannot be reconciled is a wallet
 * nobody can defend in a dispute.
 *
 * @property int $id
 * @property int $user_id
 * @property string $balance
 * @property string $pending_balance
 * @property bool $is_frozen
 */
class Wallet extends Model
{
    use DashboardModel;

    protected $fillable = ['user_id', 'balance', 'pending_balance', 'currency', 'is_frozen'];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
            'pending_balance' => 'decimal:2',
            'is_frozen' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return HasMany<WalletTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class, 'wallet_id')->latest('id');
    }

    /**
     * What the ledger actually says.
     *
     * The cached balance should equal this. When it does not, the ledger wins —
     * it is the record with the history attached.
     */
    public function ledgerBalance(): float
    {
        $credits = (float) WalletTransaction::where('wallet_id', $this->id)
            ->where('direction', 'credit')->sum('amount');

        $debits = (float) WalletTransaction::where('wallet_id', $this->id)
            ->where('direction', 'debit')->sum('amount');

        return round($credits - $debits, 2);
    }

    /**
     * Whether the cache and the ledger agree.
     */
    public function isReconciled(): bool
    {
        return abs($this->ledgerBalance() - (float) $this->balance) < 0.005;
    }

    public function hasAtLeast(float $amount): bool
    {
        return (float) $this->balance + 0.001 >= $amount;
    }
}
