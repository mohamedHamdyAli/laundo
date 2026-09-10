<?php

namespace App\Modules\Laundry\Models;

use App\Modules\City\Models\City;
use App\Modules\LaundryService\Models\LaundryService;
use App\Modules\LaundryZone\Models\LaundryZone;
use App\Modules\User\Models\User;
use App\Support\LaundryContext;
use App\Trait\DashboardModel;
use App\Trait\Scopes\Searchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A laundry is a tenant: it has its own dashboard users and, from P6 onward, its
 * own orders. It does NOT own prices — those are global and set by the super
 * admin. What a laundry controls is which services it offers (P2).
 *
 * @property int $id
 * @property string $phone
 * @property string|null $email
 * @property string|null $address
 * @property int|null $city_id
 * @property string|null $lat
 * @property string|null $lng
 * @property string|null $logo
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read mixed $name
 * @property-read City|null $city
 * @property-read Collection<int, User> $users
 * @property-read int|null $users_count
 *
 * @method static Builder<static>|Laundry newModelQuery()
 * @method static Builder<static>|Laundry newQuery()
 * @method static Builder<static>|Laundry query()
 * @method static Builder<static>|Laundry search(?string $search, array $columns = [])
 *
 * @mixin \Eloquent
 */
class Laundry extends Model
{
    use DashboardModel;
    use Searchable;

    protected $fillable = [
        'name',
        'phone',
        'email',
        'address',
        'city_id',
        'lat',
        'lng',
        'logo',
        'status',
        'approved_at',
        'rejected_at',
        'rejection_reason',
    ];

    /**
     * The coordinates the delivery fee is measured from. Nullable: a laundry
     * added before P6 has none, and DeliveryFeeCalculator says so explicitly
     * rather than quietly charging nothing.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    /**
     * The tenant key for this table is `id`, not `laundry_id`, so the
     * BelongsToLaundry trait does not apply — the scope is declared here instead.
     * A laundry user sees exactly one row: their own.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('own_laundry', function (Builder $query): void {
            $laundryId = LaundryContext::currentId();

            if ($laundryId === null) {
                return;
            }

            $query->where($query->getModel()->getTable().'.id', $laundryId);
        });
    }

    /**
     * Keep Arabic readable in the column instead of \uXXXX escapes.
     */
    protected function asJson($value, $flags = 0)
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Translatable: returns a stdClass, so it is $laundry->name->ar — never
     * $laundry->name['ar']. Matches how City and Category behave.
     */
    public function getNameAttribute($value)
    {
        return json_decode((string) $value);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_id');
    }

    /**
     * Everybody who signs in for this laundry — the owner and the staff.
     *
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'laundry_id');
    }

    /**
     * Applications waiting for somebody to decide.
     *
     * Oldest first: a queue whose age nobody can see gets worked newest-first,
     * and the laundry that has waited longest is the one most likely to give up.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('approved_at')
            ->whereNull('rejected_at')
            ->oldest('created_at');
    }

    public function isPending(): bool
    {
        return $this->approved_at === null && $this->rejected_at === null;
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    public function isRejected(): bool
    {
        return $this->rejected_at !== null;
    }

    /**
     * The account created alongside this laundry.
     *
     * Every caller that needed the owner re-derived it from `users` plus a role
     * slug; naming it once is what lets the edit screen offer a password reset
     * without guessing which of a laundry's users is the one to reset.
     *
     * Ordered rather than left to the database: `users.laundry_id` holds the
     * staff too, and nothing in the schema stops a second owner row. Oldest
     * wins, which is the account created alongside the laundry.
     *
     * Not `latestOfMany()` — its aggregate subquery is built without the
     * constraints added before it, so it picks the newest user of ANY laundry
     * role and the role filter then discards it. On a laundry with staff that
     * returns null, which is how it was caught.
     *
     * @return HasOne<User, $this>
     */
    public function owner(): HasOne
    {
        return $this->hasOne(User::class, 'laundry_id')
            ->whereHas('role', fn ($query) => $query->where('slug', 'laundry_owner'))
            ->orderBy('id');
    }

    /**
     * The zones this laundry has claimed. Read by LaundryAssigner to decide who
     * can take an order.
     *
     * @return HasMany<LaundryZone, $this>
     */
    public function zones(): HasMany
    {
        return $this->hasMany(LaundryZone::class, 'laundry_id');
    }

    /**
     * @return HasMany<LaundryService, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(LaundryService::class, 'laundry_id');
    }

    public function hasCoordinates(): bool
    {
        return $this->lat !== null && $this->lng !== null;
    }
}
