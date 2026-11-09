<?php

namespace App\Modules\Order\Models;

use App\Modules\User\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A leg held «بانتظار موافقة الإدارة» — see `TaskHolds` (2026-11-08).
 *
 * @property int $id
 * @property int $order_task_id
 * @property int $order_id
 * @property string $kind `piece_count` | `collected_amount`
 * @property string $reported
 * @property string|null $expected
 * @property array<string, mixed> $payload
 * @property string $status `pending` | `approved` | `rejected` | `withdrawn`
 * @property int|null $raised_by
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $note
 */
class TaskHold extends Model
{
    public const PIECE_COUNT = 'piece_count';

    public const COLLECTED_AMOUNT = 'collected_amount';

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    /** The driver confirmed again with the right figure before anybody decided. */
    public const WITHDRAWN = 'withdrawn';

    protected $fillable = [
        'order_task_id', 'order_id', 'kind', 'reported', 'expected', 'payload',
        'status', 'raised_by', 'decided_by', 'decided_at', 'note',
    ];

    protected function casts(): array
    {
        return [
            'reported' => 'decimal:2',
            'expected' => 'decimal:2',
            'payload' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(OrderTask::class, 'order_task_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function raisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function isMoney(): bool
    {
        return $this->kind === self::COLLECTED_AMOUNT;
    }

    /**
     * The one sentence, for the bell, the order screen and the driver.
     */
    public function describe(): string
    {
        return $this->isMoney()
            ? __('Collected :reported against :expected to collect.', [
                'reported' => moneyFormat((float) $this->reported),
                'expected' => moneyFormat((float) $this->expected),
            ])
            : __('Counted :reported pieces at the laundry against its count of :expected.', [
                'reported' => (int) $this->reported,
                'expected' => (int) $this->expected,
            ]);
    }
}
