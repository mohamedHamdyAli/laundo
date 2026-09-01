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

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'laundry_id');
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
