<?php

namespace App\Modules\Wallet\Models;

use App\Modules\User\Models\User;
use App\Modules\Wallet\Enums\TransactionReason;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One movement.
 *
 * Immutable by convention: nothing in the codebase updates one after it is
 * written. A correction is another row, which is what «تسوية» means and what
 * keeps the history honest.
 *
 * @property int $wallet_id
 * @property string $direction
 * @property string $amount
 * @property TransactionReason $reason
 * @property string $balance_after
 * @property Carbon|null $created_at
 *
 * @method static Builder<static>|WalletTransaction credits()
 * @method static Builder<static>|WalletTransaction debits()
 */
class WalletTransaction extends Model
{
    public const CREDIT = 'credit';

    public const DEBIT = 'debit';

    protected $fillable = [
        'wallet_id', 'direction', 'amount', 'reason', 'balance_after',
        'source_type', 'source_id', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'reason' => TransactionReason::class,
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Wallet, $this>
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'wallet_id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeCredits(Builder $query): Builder
    {
        return $query->where('direction', self::CREDIT);
    }

    public function scopeDebits(Builder $query): Builder
    {
        return $query->where('direction', self::DEBIT);
    }

    public function isCredit(): bool
    {
        return $this->direction === self::CREDIT;
    }

    /**
     * The signed figure the app displays: «+100 ج.م» or «-150 ج.م».
     */
    public function signedAmount(): float
    {
        return $this->isCredit() ? (float) $this->amount : -(float) $this->amount;
    }
}
