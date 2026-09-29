<?php

namespace App\Modules\Coupon\Models;

use App\Modules\Item\Models\Item;
use App\Modules\ItemCategory\Models\ItemCategory;
use App\Modules\Service\Models\Service;
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
 * @property string|null $scope_type
 * @property array<int, int>|null $scope_ids
 * @property string|null $discount_laundry_share
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

    /**
     * What a coupon may be limited to. Null is the whole order. See the
     * 2026_09_28 migration.
     */
    public const SCOPE_SERVICE = 'service';

    public const SCOPE_CATEGORY = 'category';

    public const SCOPE_ITEM = 'item';

    public const SCOPES = [self::SCOPE_SERVICE, self::SCOPE_CATEGORY, self::SCOPE_ITEM];

    protected $fillable = [
        // `user_id` is a coupon issued to one named person — a referral reward,
        // or goodwill after a complaint. Null is the ordinary case: a public
        // code anybody may use, limited by `max_redemptions`.
        'code', 'user_id', 'name', 'type', 'value', 'max_discount', 'min_order_total',
        'applies_to_delivery', 'max_redemptions', 'max_per_user',
        'starts_at', 'ends_at', 'status',
        // Who pays for the discount: the share the laundry bears, 0–100. Null
        // follows the general setting. Written only by somebody holding
        // `setting.update` — it moves money off a laundry's payout.
        'discount_laundry_share',
        // What it applies to: one kind, several of it. Null is the whole order.
        'scope_type', 'scope_ids',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'max_discount' => 'decimal:2',
            'min_order_total' => 'decimal:2',
            'applies_to_delivery' => 'boolean',
            'discount_laundry_share' => 'decimal:2',
            'scope_ids' => 'array',
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
     * The discount as a customer reads it: «20%», or «EGP 15.00».
     *
     * Added because there was no shared expression of this and one was already
     * needed twice: the offers carousel's «خصم 20%» badge, and the coupon list
     * in the panel — which had been formatting it inline in Blade, `rtrim`ing
     * the trailing zeros the `decimal:2` cast leaves on `20.00`. Two copies of
     * the same rule is how a badge ends up disagreeing with the figure an
     * operator is looking at.
     *
     * This is the *headline* figure and not what any particular basket saves —
     * `max_discount` and `min_order_total` can both reduce it. `discountFor()`
     * is the one that answers for real money.
     */
    public function discountLabel(): string
    {
        if ($this->type === self::PERCENTAGE) {
            // `decimal:2` reads back as "20.00"; nobody writes a percentage
            // that way. Trailing zeros go, and so does a bare point.
            return rtrim(rtrim((string) $this->value, '0'), '.').'%';
        }

        return moneyFormat($this->value);
    }

    /**
     * Whether this coupon would be accepted right now.
     *
     * The same three tests `CouponService::validate()` applies, so anything
     * advertising a coupon — the offers badge — can ask one question and get
     * the answer the checkout will give. Deliberately not `scopeLive()`: that
     * one's docblock claims «and not exhausted» and its query does not check
     * `redemptions_count`, so a spent coupon passes it.
     */
    public function isRedeemable(): bool
    {
        return $this->status === 'active'
            && $this->hasStarted()
            && ! $this->hasExpired()
            && ! $this->isExhausted();
    }

    /**
     * The share of this coupon's discount the laundry bears, 0–100.
     *
     * Its own figure when one was set, otherwise the general setting
     * `Coupon_Laundry_Share`, otherwise **zero — the platform pays**, which is
     * the owner's rule: «المفروض الكوبون يكون على السوبر أدمن». Read once, at
     * placement, and copied onto the order.
     */
    public function laundryShareOfDiscount(): float
    {
        $share = $this->discount_laundry_share ?? getSettingValue('Coupon_Laundry_Share');

        if ($share === null || $share === '') {
            return 0.0;
        }

        return round(max(min((float) $share, 100.0), 0.0), 2);
    }

    /**
     * Limited to part of an order, rather than the whole of it.
     */
    public function isScoped(): bool
    {
        return in_array($this->scope_type, self::SCOPES, true) && $this->scopeIds() !== [];
    }

    /**
     * @return list<int>
     */
    public function scopeIds(): array
    {
        return array_values(array_unique(array_map('intval', (array) $this->scope_ids)));
    }

    /**
     * The part of a basket this coupon applies to — the whole subtotal, the
     * whole of an order of one of its services, or only the matching pieces.
     *
     * @param  array<int, array{item_id: int, line_total: float|int|string}>  $lines  the priced lines
     */
    public function eligibleSubtotal(?int $serviceId, array $lines, float $subtotal): float
    {
        if (! $this->isScoped()) {
            return $subtotal;
        }

        return self::eligibleFor((string) $this->scope_type, $this->scopeIds(), $serviceId, $lines, $subtotal);
    }

    /**
     * The same for a limit held somewhere else — the copy stamped on an order at
     * placement, which the review reads rather than the coupon as it is today.
     *
     * @param  list<int>  $ids
     * @param  array<int, array{item_id: int, line_total: float|int|string}>  $lines
     */
    public static function eligibleFor(string $type, array $ids, ?int $serviceId, array $lines, float $subtotal): float
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if (! in_array($type, self::SCOPES, true) || $ids === []) {
            return $subtotal;
        }

        if ($type === self::SCOPE_SERVICE) {
            return in_array((int) $serviceId, $ids, true) ? $subtotal : 0.0;
        }

        $categories = $type === self::SCOPE_CATEGORY
            ? Item::whereIn('id', array_column($lines, 'item_id'))->pluck('item_category_id', 'id')
            : collect();

        $eligible = 0.0;

        foreach ($lines as $line) {
            $matches = $type === self::SCOPE_ITEM
                ? in_array((int) $line['item_id'], $ids, true)
                : in_array((int) ($categories[$line['item_id']] ?? 0), $ids, true);

            if ($matches) {
                $eligible += (float) $line['line_total'];
            }
        }

        return round($eligible, 2);
    }

    /**
     * Whether the delivery fee is discounted too. Only for a coupon on the whole
     * order or on whole orders of a service: a discount on shirts has nothing
     * to do with the journey.
     */
    public function coversDeliveryFee(): bool
    {
        return $this->applies_to_delivery
            && (! $this->isScoped() || $this->scope_type === self::SCOPE_SERVICE);
    }

    /**
     * What it applies to, in words — «قميص، بنطلون» — for the panel and the apps.
     * Null for the whole order.
     *
     * @return array{type: string, ids: list<int>, names: list<string>}|null
     */
    public function scopeSummary(): ?array
    {
        return self::scopeSummaries([$this])[$this->id] ?? null;
    }

    /**
     * The same for a list of coupons, in one query per kind rather than one per
     * coupon — the offers carousel asks for a page of them at once.
     *
     * @param  iterable<Coupon>  $coupons
     * @return array<int, array{type: string, ids: list<int>, names: list<string>}> keyed by coupon id
     */
    public static function scopeSummaries(iterable $coupons): array
    {
        $scoped = collect($coupons)->filter(fn (Coupon $coupon) => $coupon->isScoped());
        $names = [];

        foreach ($scoped->groupBy('scope_type') as $type => $group) {
            $model = match ($type) {
                self::SCOPE_SERVICE => Service::class,
                self::SCOPE_CATEGORY => ItemCategory::class,
                default => Item::class,
            };

            $names[$type] = $model::whereIn('id', $group->flatMap(fn (Coupon $coupon) => $coupon->scopeIds())->unique()->values())
                ->get()
                ->mapWithKeys(fn ($row) => [$row->id => (string) getLocalizedValue($row, 'name')])
                ->all();
        }

        return $scoped->mapWithKeys(fn (Coupon $coupon) => [$coupon->id => [
            'type' => (string) $coupon->scope_type,
            'ids' => $coupon->scopeIds(),
            'names' => collect($coupon->scopeIds())
                ->map(fn (int $id) => $names[$coupon->scope_type][$id] ?? null)
                ->filter()
                ->values()
                ->all(),
        ]])->all();
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
