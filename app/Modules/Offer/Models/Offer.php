<?php

namespace App\Modules\Offer\Models;

use App\Modules\Coupon\Models\Coupon;
use App\Modules\Offer\Enums\OfferTarget;
use App\Trait\DashboardModel;
use App\Trait\Scopes\Searchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A card in «عروض متميزة» on the customer's home screen.
 *
 * @property int $id
 * @property string|null $image
 * @property int|null $coupon_id
 * @property string $target_type
 * @property string|null $target_value
 * @property \Illuminate\Support\Carbon|null $starts_at
 * @property \Illuminate\Support\Carbon|null $ends_at
 * @property int $sort_order
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read mixed $title
 * @property-read mixed $description
 * @property-read Coupon|null $coupon
 *
 * @method static Builder<static>|Offer active()
 * @method static Builder<static>|Offer live()
 */
class Offer extends Model
{
    use DashboardModel;
    use Searchable;

    protected $fillable = [
        'image',
        'title',
        'description',
        'coupon_id',
        'target_type',
        'target_value',
        'starts_at',
        'ends_at',
        'sort_order',
        'status',
    ];

    /**
     * Without this Arabic is stored as `\uXXXX` escapes — unreadable in the
     * database and in any dump of it.
     */
    protected function asJson($value, $flags = 0)
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Decoded without `true`, so this is a **stdClass**: `$offer->title->ar`,
     * never `$offer->title['ar']`. That is the convention every translatable
     * model here follows and the Blade partials rely on it.
     */
    public function getTitleAttribute($value)
    {
        return json_decode((string) $value);
    }

    public function getDescriptionAttribute($value)
    {
        return json_decode((string) $value);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class, 'coupon_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * Active and inside its window.
     *
     * A null bound means "no bound" on either end, so an offer with neither
     * runs until somebody switches it off — which is how most of them will be
     * written, the window being there for the seasonal ones.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', 'active')
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }

    public function target(): OfferTarget
    {
        return OfferTarget::tryFrom((string) $this->target_type) ?? OfferTarget::None;
    }

    /**
     * The «خصم 20%» badge, or null when there is nothing to promise.
     *
     * Derived from the coupon rather than typed by an operator, so the badge
     * cannot advertise 20% while the code gives 15. And withheld unless the
     * coupon would actually be accepted — `isRedeemable()` runs the same three
     * tests as the checkout — because a card promising a discount that is
     * refused at the last step is worse than a card with no badge.
     */
    public function badge(): ?string
    {
        if (! $this->coupon || ! $this->coupon->isRedeemable()) {
            return null;
        }

        return $this->coupon->discountLabel();
    }
}
