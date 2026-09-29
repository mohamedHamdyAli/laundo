<?php

namespace App\Modules\Pricing\Models;

use App\Modules\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One catalogue-wide price rise — for a period, or written in for good.
 *
 * @property int $id
 * @property string $rate
 * @property string $mode
 * @property Carbon|null $ends_at
 * @property int $prices_count
 * @property int|null $applied_by
 * @property Carbon|null $ended_at
 * @property Carbon|null $undone_at
 * @property int|null $undone_by
 * @property int $restored_count
 * @property Carbon|null $created_at
 * @property-read User|null $appliedBy
 * @property-read User|null $undoneBy
 */
class PriceChange extends Model
{
    public const PERIOD = 'period';

    public const PERMANENT = 'permanent';

    protected $fillable = [
        'rate', 'mode', 'ends_at', 'prices_count', 'applied_by',
        'ended_at', 'undone_at', 'undone_by', 'restored_count',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:2',
            'ends_at' => 'datetime',
            'ended_at' => 'datetime',
            'undone_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<PriceChangeItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PriceChangeItem::class);
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function undoneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'undone_by');
    }

    public function isPermanent(): bool
    {
        return $this->mode === self::PERMANENT;
    }

    public function isUndone(): bool
    {
        return $this->undone_at !== null;
    }

    /**
     * A period rise still in force: not taken off, and not past its date.
     */
    public function isRunning(): bool
    {
        return $this->mode === self::PERIOD
            && $this->ended_at === null
            && ($this->ends_at === null || $this->ends_at->isFuture());
    }
}
