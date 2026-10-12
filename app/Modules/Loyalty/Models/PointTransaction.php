<?php

namespace App\Modules\Loyalty\Models;

use App\Modules\Loyalty\Enums\PointReason;
use App\Modules\User\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One movement of points — and, when it is a credit, a lot.
 *
 * Immutable except for the two columns that describe what is left of a lot:
 * `remaining` falls as the lot is spent or expires, and `expiry_warned_at` is
 * stamped once the customer has been told it is about to lapse. Everything else
 * is written once, as on the wallet: a correction is another row.
 *
 * @property int $id
 * @property int $user_id
 * @property string $direction
 * @property int $points
 * @property PointReason $reason
 * @property int $balance_after
 * @property int|null $remaining
 * @property Carbon|null $expires_at
 * @property Carbon|null $expiry_warned_at
 * @property string|null $source_type
 * @property int|null $source_id
 * @property int|null $reverses_id
 * @property string|null $dedupe_key
 * @property string|null $note
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property-read User|null $author
 *
 * @method static Builder<static>|PointTransaction credits()
 * @method static Builder<static>|PointTransaction debits()
 * @method static Builder<static>|PointTransaction live()
 */
class PointTransaction extends Model
{
    public const CREDIT = 'credit';

    public const DEBIT = 'debit';

    protected $fillable = [
        'user_id', 'direction', 'points', 'reason', 'balance_after',
        'remaining', 'expires_at', 'expiry_warned_at',
        'source_type', 'source_id', 'reverses_id', 'dedupe_key', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'reason' => PointReason::class,
            'points' => 'integer',
            'balance_after' => 'integer',
            'remaining' => 'integer',
            'expires_at' => 'datetime',
            'expiry_warned_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Which lots a debit ate, and how much of each.
     *
     * @return HasMany<PointLotSpend, $this>
     */
    public function spends(): HasMany
    {
        return $this->hasMany(PointLotSpend::class, 'debit_id');
    }

    public function scopeCredits(Builder $query): Builder
    {
        return $query->where('direction', self::CREDIT);
    }

    public function scopeDebits(Builder $query): Builder
    {
        return $query->where('direction', self::DEBIT);
    }

    /**
     * Lots with points left on them that have not lapsed — what can be spent.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('direction', self::CREDIT)
            ->where('remaining', '>', 0)
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isCredit(): bool
    {
        return $this->direction === self::CREDIT;
    }

    /**
     * «+10» or «-45», the way both ledgers print it.
     */
    public function signedPoints(): int
    {
        return $this->isCredit() ? $this->points : -$this->points;
    }
}
