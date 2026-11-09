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
 * @property string $kind `piece_count` | `hanger_count` | `collected_amount`
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

    /** The hangers at the collection from the laundry (item 11, 2026-11-09). */
    public const HANGER_COUNT = 'hanger_count';

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

    /**
     * @return BelongsTo<OrderTask, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(OrderTask::class, 'order_task_id');
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function raisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
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
        if ($this->isMoney()) {
            return __('Collected :reported against :expected to collect.', [
                'reported' => moneyFormat((float) $this->reported),
                'expected' => moneyFormat((float) $this->expected),
            ]);
        }

        if ($this->kind === self::HANGER_COUNT) {
            return $this->hangersSentence((int) $this->reported, (int) $this->expected);
        }

        $pieces = __('Counted :reported pieces at the laundry against its count of :expected.', [
            'reported' => (int) $this->reported,
            'expected' => (int) $this->expected,
        ]);

        // The hangers disagreed in the same confirmation: one question, both
        // figures — an approval accepts the whole of it.
        $hangers = $this->payload['hanger_mismatch'] ?? null;

        return is_array($hangers)
            ? $pieces.' '.$this->hangersSentence((int) $hangers['reported'], (int) $hangers['expected'])
            : $pieces;
    }

    private function hangersSentence(int $reported, int $expected): string
    {
        return __('Received :reported hangers at the laundry against the :expected it wrote.', [
            'reported' => $reported,
            'expected' => $expected,
        ]);
    }
}
