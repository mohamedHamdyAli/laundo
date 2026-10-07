<?php

namespace App\Modules\Zone\Models;

use App\Modules\Address\Models\Address;
use App\Modules\User\Models\User;
use App\Trait\DashboardModel;
use App\Trait\Scopes\Searchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A customer who tried to order to or from an address no zone covers.
 *
 * Written when the order — or the quote before it — is refused with
 * `out_of_coverage`. The app has just told them «we will contact you as soon as
 * we reach your area», so each row is a promise with a phone number on it.
 *
 * In the Zone module because coverage is what zones are: the row is evidence
 * for where to draw the next one, and it reads the address's zone to say when
 * the promise can be kept (`isNowCovered()`).
 *
 * `use DashboardModel` is what makes `PermissionGenerator` emit
 * `coverage_request.{view,create,update,delete,toggle}` once the class is listed
 * in `config/dashboard.php`.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $address_id
 * @property string $lat
 * @property string $lng
 * @property string|null $address_line
 * @property int $attempts
 * @property Carbon $last_attempt_at
 * @property Carbon|null $contacted_at
 * @property int|null $contacted_by
 * @property Carbon|null $created_at
 * @property-read User|null $customer
 * @property-read Address|null $address
 * @property-read User|null $contacter
 *
 * @method static Builder<static>|CoverageRequest readyToCall()
 */
class CoverageRequest extends Model
{
    use DashboardModel;
    use Searchable;

    protected $fillable = [
        'user_id', 'address_id', 'lat', 'lng', 'address_line',
        'attempts', 'last_attempt_at', 'contacted_at', 'contacted_by',
    ];

    protected function casts(): array
    {
        return [
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'attempts' => 'integer',
            'last_attempt_at' => 'datetime',
            'contacted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Address, $this>
     */
    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'address_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function contacter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contacted_by');
    }

    /**
     * Nobody has rung them, and their address is served now — the moment the
     * promise can be kept. What the sidebar counts: work waiting on a person.
     * A row still outside every zone is not work yet, so it is not counted.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeReadyToCall(Builder $query): Builder
    {
        return $query->whereNull($query->qualifyColumn('contacted_at'))
            ->whereHas('address', fn (Builder $address) => $address->covered());
    }

    public function isContacted(): bool
    {
        return $this->contacted_at !== null;
    }

    /**
     * The address has a zone now — a zone was drawn over it, or the customer
     * moved the pin. Read off the address as it is today, so it follows the map
     * without anything having to update this row.
     */
    public function isNowCovered(): bool
    {
        return $this->address?->isCovered() ?? false;
    }
}
