<?php

namespace App\Modules\Order\Models;

use App\Modules\Driver\Models\Driver;
use App\Modules\Order\Enums\TaskFailureReason;
use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Enums\TaskType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One leg of an order's journey.
 *
 * Not tenant-scoped, and that is deliberate: a task belongs to a *driver*, and
 * drivers are not tenants. The isolation on the driver API comes from querying
 * through `$request->user()->tasks()`, the same rule as customers and their
 * orders.
 *
 * @property int $id
 * @property int $order_id
 * @property TaskType $type
 * @property int $sequence
 * @property TaskStatus $status
 * @property int|null $driver_id
 * @property Carbon|null $assigned_at
 * @property Carbon|null $due_at
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property int|null $piece_count
 * @property string|null $receiver_name
 * @property string|null $signature_path
 * @property string|null $collected_amount
 * @property TaskFailureReason|null $failure_reason
 * @property string|null $failure_note
 * @property int $attempts
 *
 * @method static Builder<static>|OrderTask open()
 * @method static Builder<static>|OrderTask queued()
 * @method static Builder<static>|OrderTask late()
 */
class OrderTask extends Model
{
    /**
     * Two failures and a task stops going round the pool.
     *
     * A task nobody can complete is not a dispatch problem, and sending a third
     * driver to the same wrong address only spends somebody's afternoon.
     */
    public const MAX_ATTEMPTS = 2;

    /**
     * The statuses that mean "still to be done".
     *
     * Named once so `scopeOpen` and `scopeLate` cannot drift apart — a late task
     * that is not an open task would be a contradiction.
     *
     * @var array<int, string>
     */
    private const OPEN_STATUSES = ['pending', 'assigned', 'started'];

    protected $fillable = [
        'order_id', 'type', 'sequence', 'status', 'driver_id', 'assigned_at', 'due_at',
        'started_at', 'completed_at', 'piece_count', 'receiver_name', 'signature_path',
        'collected_amount', 'failure_reason', 'failure_note', 'attempts', 'note',
    ];

    protected function casts(): array
    {
        return [
            'type' => TaskType::class,
            'status' => TaskStatus::class,
            'failure_reason' => TaskFailureReason::class,
            'assigned_at' => 'datetime',
            'due_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'collected_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'driver_id');
    }

    /**
     * Still to be done.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    /**
     * The dispatch queue: tasks nobody holds.
     */
    public function scopeQueued(Builder $query): Builder
    {
        return $query->where('status', TaskStatus::Pending->value);
    }

    /**
     * «متأخرة» — the window has passed and the task is not finished.
     */
    public function scopeLate(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES)
            ->whereNotNull('due_at')
            ->where('due_at', '<', now());
    }

    public function isLate(): bool
    {
        return $this->status->isOpen()
            && $this->due_at !== null
            && $this->due_at->isPast();
    }

    /**
     * «المدة» in the history screen.
     */
    public function durationMinutes(): ?int
    {
        if (! $this->started_at || ! $this->completed_at) {
            return null;
        }

        return (int) $this->started_at->diffInMinutes($this->completed_at);
    }

    /**
     * Whether the leg before this one is done.
     *
     * The safety property of the whole phase: nothing can be delivered that was
     * never collected. Leg 1 has no predecessor and is therefore always ready.
     */
    public function predecessorComplete(): bool
    {
        if ($this->sequence <= 1) {
            return true;
        }

        return static::where('order_id', $this->order_id)
            ->where('sequence', $this->sequence - 1)
            ->where('status', TaskStatus::Completed->value)
            ->exists();
    }

    /**
     * Whether a driver may act on this now.
     */
    public function isActionable(): bool
    {
        return $this->status->isOpen() && $this->predecessorComplete();
    }

    /**
     * Whether this task has run out of second chances.
     */
    public function isExhausted(): bool
    {
        return $this->attempts >= self::MAX_ATTEMPTS;
    }

    public function signatureUrl(): ?string
    {
        return $this->signature_path ? getImageassetUrl($this->signature_path) : null;
    }
}
