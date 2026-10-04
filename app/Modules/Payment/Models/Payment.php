<?php

namespace App\Modules\Payment\Models;

use App\Modules\Driver\Models\Driver;
use App\Modules\Order\Models\Order;
use App\Modules\Payment\Enums\PaymentMethod;
use App\Modules\Payment\Enums\PaymentStatus;
use App\Modules\User\Models\User;
use App\Trait\DashboardModel;
use App\Trait\Scopes\Searchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One attempt to pay for an order.
 *
 * @property int $id
 * @property int $order_id
 * @property int $user_id
 * @property string $provider
 * @property PaymentMethod $method
 * @property string|null $provider_reference
 * @property string $amount
 * @property string $currency
 * @property PaymentStatus $status
 * @property Carbon|null $captured_at
 * @property array<string, mixed>|null $payload
 * @property int|null $collected_by
 * @property int|null $order_task_id
 * @property Carbon|null $handed_over_at
 * @property int|null $received_by
 *
 * @method static Builder<static>|Payment captured()
 * @method static Builder<static>|Payment open()
 * @method static Builder<static>|Payment withDrivers()
 */
class Payment extends Model
{
    // For PermissionGenerator. Without it the ledger routes would be gated on
    // permissions that do not exist — a 403 nobody can grant their way out of.
    use DashboardModel;
    use Searchable;

    protected $fillable = [
        'order_id', 'user_id', 'provider', 'method', 'provider_reference',
        'amount', 'currency', 'status', 'authorised_at', 'captured_at',
        'failed_at', 'failure_reason', 'payload',
        // Cash taken at the door: who took it, on which leg, and when the office
        // received it from them.
        'collected_by', 'order_task_id', 'handed_over_at', 'received_by',
    ];

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'amount' => 'decimal:2',
            'authorised_at' => 'datetime',
            'captured_at' => 'datetime',
            'failed_at' => 'datetime',
            'handed_over_at' => 'datetime',
            'payload' => 'array',
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
     * The driver who took this cash at the door.
     *
     * @return BelongsTo<Driver, $this>
     */
    public function collector(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'collected_by');
    }

    /**
     * Who at the office received it from the driver.
     *
     * @return BelongsTo<User, $this>
     */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * Cash a driver took at a door and has not handed in yet.
     */
    public function scopeWithDrivers(Builder $query): Builder
    {
        return $query->where('method', PaymentMethod::Cash->value)
            ->where('status', PaymentStatus::Captured->value)
            ->whereNotNull('collected_by')
            ->whereNull('handed_over_at');
    }

    public function scopeCaptured(Builder $query): Builder
    {
        return $query->where('status', PaymentStatus::Captured->value);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            PaymentStatus::Pending->value,
            PaymentStatus::Authorised->value,
        ]);
    }
}
