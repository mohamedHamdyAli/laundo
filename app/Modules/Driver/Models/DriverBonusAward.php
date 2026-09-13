<?php

namespace App\Modules\Driver\Models;

use App\Modules\User\Models\User;
use App\Trait\DashboardModel;
use App\Trait\Scopes\Searchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One driver's monthly bonus, and the four numbers it was decided on.
 *
 * The measurements are **stored, not recomputed**. A driver asking why September
 * paid 500 and October nothing has to be shown what the decision was made from,
 * and a rating that arrives late must not silently restate a month already paid.
 *
 * @property int $id
 * @property int $driver_id
 * @property int|null $driver_bonus_rule_id
 * @property string $period
 * @property int $orders_count
 * @property string|null $on_time_rate
 * @property string|null $avg_delivery_rating
 * @property int $failed_tasks
 * @property int|null $tier_min_orders
 * @property string $amount
 * @property string $status
 * @property array<int, string>|null $gate_failures
 * @property Carbon|null $approved_at
 * @property int|null $approved_by
 * @property string|null $note
 * @property-read User|null $driver
 * @property-read DriverBonusRule|null $rule
 *
 * @method static Builder<static>|DriverBonusAward due()
 * @method static Builder<static>|DriverBonusAward approved()
 * @method static Builder<static>|DriverBonusAward search(?string $search, array $columns = [])
 *
 * @mixin \Eloquent
 */
class DriverBonusAward extends Model
{
    use DashboardModel;
    use Searchable;

    /** Worked out and waiting for a person. Nothing has moved. */
    public const DUE = 'due';

    /** Approved, and the wallet credited. */
    public const APPROVED = 'approved';

    /** A person decided it is not owed, or a gate blocked it. */
    public const REJECTED = 'rejected';

    protected $fillable = [
        'driver_id', 'driver_bonus_rule_id', 'period',
        'orders_count', 'on_time_rate', 'avg_delivery_rating', 'failed_tasks',
        'tier_min_orders', 'amount', 'status', 'gate_failures',
        'approved_at', 'approved_by', 'note',
    ];

    protected function casts(): array
    {
        return [
            'orders_count' => 'integer',
            'on_time_rate' => 'decimal:2',
            'avg_delivery_rating' => 'decimal:2',
            'failed_tasks' => 'integer',
            'tier_min_orders' => 'integer',
            'amount' => 'decimal:2',
            'gate_failures' => 'array',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * The driver, read off `users` rather than through the Driver model.
     *
     * Same reasoning as `DriverEarning::payee()`: the Driver model carries a
     * global scope on the driver role, so a person who has since been moved to
     * another role would vanish from their own paid history.
     *
     * @return BelongsTo<User, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    /**
     * @return BelongsTo<DriverBonusRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(DriverBonusRule::class, 'driver_bonus_rule_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeDue(Builder $query): Builder
    {
        return $query->where('status', self::DUE);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::APPROVED);
    }

    public function isDue(): bool
    {
        return $this->status === self::DUE;
    }

    /**
     * True when a quality gate stopped this month from paying.
     *
     * Distinct from «earned nothing»: a driver who missed every tier and a
     * driver who hit one and then lost it on their on-time rate are two very
     * different conversations, and the screen has to tell them apart.
     */
    public function wasGated(): bool
    {
        return ! empty($this->gate_failures);
    }

    /**
     * Whether this month can still be recomputed.
     *
     * An approved row is frozen: real money moved against those four numbers,
     * and a row that restates itself afterwards is a row nobody can audit.
     */
    public function isRecomputable(): bool
    {
        return $this->status !== self::APPROVED;
    }
}
