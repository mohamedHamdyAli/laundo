<?php

namespace App\Modules\Payment\Models;

use App\Modules\Driver\Models\Driver;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use App\Modules\User\Models\User;
use App\Trait\DashboardModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What one completed leg earned.
 *
 * @property int $driver_id
 * @property int $order_id
 * @property int $order_task_id
 * @property string $amount
 * @property string $basis
 * @property string $rate
 * @property string $status
 * @property Carbon|null $released_at
 *
 * @method static Builder<static>|DriverEarning pending()
 * @method static Builder<static>|DriverEarning released()
 */
class DriverEarning extends Model
{
    // For PermissionGenerator. Without it the ledger routes would be gated on
    // permissions that do not exist — a 403 nobody can grant their way out of.
    use DashboardModel;

    public const PENDING = 'pending';

    public const RELEASED = 'released';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'driver_id', 'order_id', 'order_task_id', 'amount', 'basis', 'rate',
        'status', 'released_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'basis' => 'decimal:2',
            'rate' => 'decimal:4',
            'released_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'driver_id');
    }

    /**
     * The person owed the money.
     *
     * `driver()` goes through the `Driver` model, whose global scope requires the
     * `driver` role — right for the driver API, wrong for a ledger: move somebody
     * off driving and every pound still owed to them vanishes from the screen that
     * decides who gets paid. This relation asks the users table directly, so a
     * payee stays a payee whatever their role became.
     *
     * @return BelongsTo<User, $this>
     */
    public function payee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * @return BelongsTo<OrderTask, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(OrderTask::class, 'order_task_id');
    }

    /** «الرصيد المعلق». */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }

    public function scopeReleased(Builder $query): Builder
    {
        return $query->where('status', self::RELEASED);
    }

    /**
     * The sum, in words, for a driver asking why a job paid what it did.
     */
    public function explain(): string
    {
        return sprintf('%s x %s%%', moneyFormat($this->basis), rtrim(rtrim(number_format((float) $this->rate * 100, 2), '0'), '.'));
    }
}
