<?php

namespace App\Modules\Coupon\Models;

use App\Trait\DashboardModel;
use App\Trait\Scopes\Searchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A discount code.
 *
 * @property int $id
 * @property string $code
 * @property int|null $user_id
 * @property string $type
 * @property string $value
 * @property string|null $max_discount
 * @property string|null $min_order_total
 * @property bool $applies_to_delivery
 * @property int|null $max_redemptions
 * @property int $max_per_user
 * @property int $redemptions_count
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property string $status
 * @property-read mixed $name
 *
 * @method static Builder<static>|Coupon live()
 */
class Coupon extends Model
{
    use DashboardModel;
    use Searchable;

    public const FIXED = 'fixed';

    public const PERCENTAGE = 'percentage';

    protected $fillable = [
        // `user_id` is a coupon issued to one named person — a referral reward,
        // or goodwill after a complaint. Null is the ordinary case: a public
        // code anybody may use, limited by `max_redemptions`.
        'code', 'user_id', 'name', 'type', 'value', 'max_discount', 'min_order_total',
        'applies_to_delivery', 'max_redemptions', 'max_per_user',
        'starts_at', 'ends_at', 'status',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'max_discount' => 'decimal:2',
            'min_order_total' => 'decimal:2',
            'applies_to_delivery' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    protected function asJson($value, $flags = 0)
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    public function getNameAttribute($value)
    {
        return json_decode((string) $value);
    }

    /**
     * @return HasMany<CouponRedemption, $this>
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class, 'coupon_id');
    }

    /**
     * Active, in date, and not exhausted.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', 'active')
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }

    public function hasStarted(): bool
    {
        return $this->starts_at === null || $this->starts_at->isPast();
    }

    public function hasExpired(): bool
    {
        return $this->ends_at !== null && $this->ends_at->isPast();
    }

    public function isExhausted(): bool
    {
        return $this->max_redemptions !== null
            && $this->redemptions_count >= $this->max_redemptions;
    }

    /**
     * What this coupon takes off a given basket.
     *
     * The ceiling matters: a percentage without one is an open cheque on a large
     * order, which is how a marketing campaign becomes an incident.
     */
    public function discountFor(float $subtotal, float $deliveryFee = 0): float
    {
        $base = $subtotal + ($this->applies_to_delivery ? $deliveryFee : 0);

        $discount = $this->type === self::PERCENTAGE
            ? $base * ((float) $this->value / 100)
            : (float) $this->value;

        if ($this->max_discount !== null) {
            $discount = min($discount, (float) $this->max_discount);
        }

        // Never more than what is being discounted.
        return round(min($discount, $base), 2);
    }
}
