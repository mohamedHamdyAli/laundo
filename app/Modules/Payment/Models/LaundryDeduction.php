<?php

namespace App\Modules\Payment\Models;

use App\Modules\User\Models\User;
use App\Trait\BelongsToLaundry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One deduction standing against one laundry.
 *
 * Carries `laundry_id`, so it carries `BelongsToLaundry` — CLAUDE.md's rule, and
 * not a formality here: without the scope a laundry owner granted the revenue
 * screen would read every other tenant's deductions and the reasons written on
 * them, which is the most sensitive column on the page.
 *
 * The trait's `creating` hook overwrites `laundry_id` with the actor's own when
 * the actor is a tenant. That never fires in practice — the write routes are
 * gated on `setting.update`, which no laundry role holds — and if it ever did,
 * overwriting is the safe direction: a tenant could only ever deduct from
 * themselves.
 *
 * @property int $id
 * @property int $laundry_id
 * @property string $amount
 * @property string $reason
 * @property string $status
 * @property int|null $created_by
 * @property int|null $reversed_by
 * @property Carbon|null $reversed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $author
 * @property-read User|null $reverser
 *
 * @method static Builder<static>|LaundryDeduction applied()
 *
 * @mixin \Eloquent
 */
class LaundryDeduction extends Model
{
    use BelongsToLaundry;

    /** Standing against the laundry, and subtracted from what it is paid. */
    public const APPLIED = 'applied';

    /** Withdrawn. Kept, because the reason is part of the laundry's history. */
    public const REVERSED = 'reversed';

    protected $fillable = [
        'laundry_id', 'amount', 'reason', 'status',
        'created_by', 'reversed_by', 'reversed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        // `decimal:2` for the same reason Laundry casts its coordinates: the
        // suite runs on SQLite and the app on MariaDB, and an uncast decimal
        // comes back a float from one and a string from the other.
        return [
            'amount' => 'decimal:2',
            'reversed_at' => 'datetime',
        ];
    }

    /**
     * The ones that still count.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeApplied(Builder $query): Builder
    {
        return $query->where('status', self::APPLIED);
    }

    public function isApplied(): bool
    {
        return $this->status === self::APPLIED;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }
}
