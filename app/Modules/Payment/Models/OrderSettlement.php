<?php

namespace App\Modules\Payment\Models;

use App\Modules\Laundry\Models\Laundry;
use App\Modules\Order\Models\Order;
use App\Trait\BelongsToLaundry;
use App\Trait\DashboardModel;
use App\Trait\Scopes\Searchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * How one order's money was divided between the platform and the laundry.
 *
 * **Tenant-scoped, unlike `DriverEarning` and `Payment`.** Those two are the
 * platform collecting and the platform owing a driver; a laundry is party to
 * neither, so they are protected by permission alone. A settlement is different:
 * the laundry is one of the two parties to it and has to be able to read its own
 * — «عشان يبقو شايفين كل حاجه تخصهم» — which means the row must be filtered
 * rather than merely gated, or granting the permission would show every laundry
 * what every other laundry earns.
 *
 * @property int $id
 * @property int $order_id
 * @property int|null $laundry_id
 * @property string $basis
 * @property string $commission_rate
 * @property string $commission_amount
 * @property string $laundry_amount
 * @property string $tax_amount
 * @property string $status
 * @property Carbon|null $settled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Order|null $order
 * @property-read Laundry|null $laundry
 *
 * @method static Builder<static>|OrderSettlement newModelQuery()
 * @method static Builder<static>|OrderSettlement newQuery()
 * @method static Builder<static>|OrderSettlement query()
 * @method static Builder<static>|OrderSettlement pending()
 * @method static Builder<static>|OrderSettlement settled()
 * @method static Builder<static>|OrderSettlement search(?string $search, array $columns = [])
 *
 * @mixin \Eloquent
 */
class OrderSettlement extends Model
{
    use BelongsToLaundry;
    use DashboardModel;
    use Searchable;

    /** Recorded, and nothing has moved yet. */
    public const PENDING = 'pending';

    /** Both wallets credited. */
    public const SETTLED = 'settled';

    /** The order never completed, so nothing is owed. */
    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'order_id', 'laundry_id',
        'basis', 'commission_rate', 'commission_amount', 'laundry_amount',
        'tax_amount', 'status', 'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'basis' => 'decimal:2',
            'commission_rate' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'laundry_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'settled_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * The charges that made up the commission.
     *
     * Always eager-loaded where the total is shown: a settlement's headline
     * figure is a sum, and «العمولة ٣١ ج» is a number a laundry can only accept
     * or argue with until it can see the three charges behind it.
     *
     * @return HasMany<OrderSettlementLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderSettlementLine::class, 'order_settlement_id')->orderBy('id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSettled(Builder $query): Builder
    {
        return $query->where('status', self::SETTLED);
    }

    /**
     * Prove the split adds back up to what it divided.
     *
     * Redundant by construction, exactly like `wallet_transactions.balance_after`
     * — and for the same reason: a settlement whose halves do not sum to its
     * basis is detectable rather than merely wrong.
     */
    public function reconciles(): bool
    {
        return abs(
            round((float) $this->commission_amount + (float) $this->laundry_amount, 2)
            - round((float) $this->basis, 2)
        ) < 0.01;
    }

    /**
     * Prove the charges add up to the commission they are supposed to explain.
     *
     * A second redundancy on top of `reconciles()`, and a different one: that
     * asks whether the split adds back to what it divided, this asks whether the
     * breakdown adds back to the total shown. A settlement can satisfy one and
     * fail the other, and the failure would be a laundry shown three lines that
     * do not come to the number it was charged.
     */
    public function linesReconcile(): bool
    {
        if ($this->lines->isEmpty()) {
            return (float) $this->commission_amount === 0.0;
        }

        return abs(
            round((float) $this->lines->sum(fn ($line) => (float) $line->amount), 2)
            - round((float) $this->commission_amount, 2)
        ) < 0.01;
    }
}
