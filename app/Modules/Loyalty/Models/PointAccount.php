<?php

namespace App\Modules\Loyalty\Models;

use App\Modules\User\Models\User;
use App\Trait\DashboardModel;
use App\Trait\Scopes\Searchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer's points — «نقاط العملاء».
 *
 * `balance` is a cache. The truth is the sum of the customer's
 * `point_transactions`, and `isReconciled()` is what proves the two agree. Only
 * `PointsService` writes it.
 *
 * Also the permission subject behind the panel's screen: `point_account.view`
 * to read the ledgers, `point_account.update` to add or take points by hand.
 *
 * @property int $id
 * @property int $user_id
 * @property int $balance
 * @property-read User|null $owner
 *
 * @method static Builder<static>|PointAccount withLedger()
 * @method static Builder<static>|PointAccount outOfBalance()
 */
class PointAccount extends Model
{
    use DashboardModel;
    use Searchable;

    protected $fillable = ['user_id', 'balance'];

    protected function casts(): array
    {
        return [
            'balance' => 'integer',
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
     * @return HasMany<PointTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(PointTransaction::class, 'user_id', 'user_id')->latest('id');
    }

    /**
     * The three sums the reconciliation needs, as columns of the same query —
     * so a list of accounts proves itself without three queries a row.
     *
     * Credits and debits are summed apart and compared by addition: the
     * columns are unsigned, and MySQL refuses a subtraction that would go
     * below zero on them.
     *
     * @param  Builder<PointAccount>  $query
     * @return Builder<PointAccount>
     */
    public function scopeWithLedger(Builder $query): Builder
    {
        $sum = fn (string $expression) => PointTransaction::query()
            ->selectRaw("coalesce(sum({$expression}), 0)")
            ->whereColumn('point_transactions.user_id', 'point_accounts.user_id');

        return $query
            ->select('point_accounts.*')
            ->selectSub($sum("case when direction = 'credit' then points else 0 end"), 'ledger_credits')
            ->selectSub($sum("case when direction = 'debit' then points else 0 end"), 'ledger_debits')
            ->selectSub($sum("case when direction = 'credit' then remaining else 0 end"), 'ledger_lots');
    }

    /**
     * Accounts whose cache, ledger and lots disagree, counted in SQL.
     *
     * @param  Builder<PointAccount>  $query
     * @return Builder<PointAccount>
     */
    public function scopeOutOfBalance(Builder $query): Builder
    {
        $sum = fn (string $expression) => '(select coalesce(sum('.$expression.'), 0) from point_transactions'
            .' where point_transactions.user_id = point_accounts.user_id)';

        $credits = $sum("case when direction = 'credit' then points else 0 end");
        $debits = $sum("case when direction = 'debit' then points else 0 end");
        $lots = $sum("case when direction = 'credit' then remaining else 0 end");

        return $query->where(fn (Builder $q) => $q
            ->whereRaw("{$credits} <> point_accounts.balance + {$debits}")
            ->orWhereRaw("{$lots} <> point_accounts.balance"));
    }

    /**
     * What the ledger says, credits less debits.
     */
    public function ledgerBalance(): int
    {
        if ($this->hasLedgerColumns()) {
            return (int) $this->getAttribute('ledger_credits') - (int) $this->getAttribute('ledger_debits');
        }

        $credits = (int) PointTransaction::where('user_id', $this->user_id)->credits()->sum('points');
        $debits = (int) PointTransaction::where('user_id', $this->user_id)->debits()->sum('points');

        return $credits - $debits;
    }

    /**
     * Whether the cache, the ledger and the lots all say the same thing.
     *
     * Three figures, because the lots are a second account of the same points:
     * what is left on every credit must add up to the balance too, or a spend
     * took points from somewhere that never recorded it.
     */
    public function isReconciled(): bool
    {
        $lots = $this->hasLedgerColumns()
            ? (int) $this->getAttribute('ledger_lots')
            : (int) PointTransaction::where('user_id', $this->user_id)->credits()->sum('remaining');

        return $this->ledgerBalance() === (int) $this->balance && $lots === (int) $this->balance;
    }

    /**
     * Whether this row was read through `withLedger()`.
     */
    private function hasLedgerColumns(): bool
    {
        return array_key_exists('ledger_credits', $this->getAttributes());
    }
}
