<?php

namespace App\Modules\Order\Models;

use App\Modules\Order\Enums\RatingTag;
use App\Modules\User\Models\User;
use App\Trait\BelongsToLaundry;
use App\Trait\DashboardModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One customer's verdict on one order.
 *
 * The subject is the laundry — that is the business decision — but the aspects
 * are stored one per column rather than averaged into it, because «التوصيل
 * والاستلام» describes the driver, not the laundry. Keeping them apart means a
 * laundry is not marked down for a late driver, and the delivery score can be
 * attributed to the driver later without a migration.
 *
 * @property int $id
 * @property int $order_id
 * @property int $user_id
 * @property int|null $laundry_id
 * @property int $overall
 * @property int|null $service_quality
 * @property int|null $delivery
 * @property int|null $timing
 * @property array<int, string>|null $tags
 * @property string|null $comment
 * @property Carbon|null $created_at
 * @property-read Order|null $order
 * @property-read User|null $customer
 *
 * @method static Builder<static>|OrderRating poor()
 */
class OrderRating extends Model
{
    // Scoped, so a laundry owner reads only its own verdicts. The creating hook
    // in this trait returns early for a customer (no laundry_id), which is why
    // the value written by the service survives.
    use BelongsToLaundry;

    // For PermissionGenerator, so `order_rating.*` exists to gate the screen on.
    use DashboardModel;

    /** The score below which a rating is treated as a complaint. */
    public const POOR_AT_OR_BELOW = 2;

    protected $fillable = [
        'order_id', 'user_id', 'laundry_id',
        'overall', 'service_quality', 'delivery', 'timing',
        'tags', 'comment',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'overall' => 'integer',
            'service_quality' => 'integer',
            'delivery' => 'integer',
            'timing' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The ones somebody has to answer.
     *
     * A low score alone is a number; a low score with a comment is a customer
     * waiting for a reply, and the design's own placeholder calls that box «اكتب
     * ملاحظاتك أو شكواك».
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePoor(Builder $query): Builder
    {
        return $query->where('overall', '<=', self::POOR_AT_OR_BELOW);
    }

    /**
     * The chips, as enum cases, skipping anything no longer recognised.
     *
     * A tag removed from the enum must not throw on a list page — the row is
     * historical and the rest of it is still true.
     *
     * @return array<int, RatingTag>
     */
    public function tagCases(): array
    {
        return collect($this->tags ?? [])
            ->map(fn ($value) => RatingTag::tryFrom((string) $value))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Whether the customer filled in any of the three aspect rows.
     *
     * Used to tell "rated 4 and skipped the detail" from "rated 4 across the
     * board", which are different pieces of information.
     */
    public function hasAspectDetail(): bool
    {
        return $this->service_quality !== null
            || $this->delivery !== null
            || $this->timing !== null;
    }
}
