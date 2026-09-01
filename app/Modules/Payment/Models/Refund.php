<?php

namespace App\Modules\Payment\Models;

use App\Modules\Order\Models\Order;
use App\Modules\User\Models\User;
use App\Trait\DashboardModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * «طلب استرداد».
 *
 * @property int $order_id
 * @property int $user_id
 * @property string $amount
 * @property string $reason
 * @property string $status
 * @property string|null $destination
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $settled_at
 *
 * @method static Builder<static>|Refund pending()
 */
class Refund extends Model
{
    use DashboardModel;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const SETTLED = 'settled';

    public const TO_WALLET = 'wallet';

    public const TO_SOURCE = 'source';

    protected $fillable = [
        'order_id', 'user_id', 'payment_id', 'amount', 'reason', 'note',
        'status', 'destination', 'reviewed_by', 'reviewed_at', 'review_note', 'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'reviewed_at' => 'datetime',
            'settled_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * «قيد المراجعة» — the queue somebody has to work through.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    /**
     * Approved but not yet paid out — the case somebody has to chase, and the
     * reason approving and settling are separate columns.
     */
    public function isAwaitingSettlement(): bool
    {
        return $this->status === self::APPROVED && $this->settled_at === null;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::PENDING => 'Under review',
            self::APPROVED => 'Approved',
            self::REJECTED => 'Rejected',
            self::SETTLED => 'Refunded',
            default => $this->status,
        };
    }
}
