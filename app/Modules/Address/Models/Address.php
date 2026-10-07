<?php

namespace App\Modules\Address\Models;

use App\Modules\City\Models\City;
use App\Modules\User\Models\User;
use App\Modules\Zone\Models\Zone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's saved address.
 *
 * Not registered in config/dashboard.php: addresses belong to a customer and are
 * managed through the API, so they need no permission set of their own. They are
 * visible in the dashboard through the customer's own page.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $label
 * @property int|null $city_id
 * @property int|null $zone_id
 * @property string $street
 * @property string|null $building
 * @property string|null $floor
 * @property string|null $apartment
 * @property string|null $landmark
 * @property string|null $notes
 * @property string|null $contact_phone
 * @property string $lat
 * @property string $lng
 * @property bool $is_default
 *
 * @method static Builder<static>|Address covered()
 */
class Address extends Model
{
    protected $fillable = [
        'user_id',
        'label',
        'city_id',
        'zone_id',
        'street',
        'building',
        'floor',
        'apartment',
        'landmark',
        'notes', 'driver_note',
        'contact_phone',
        'lat',
        'lng',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<City, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_id');
    }

    /**
     * @return BelongsTo<Zone, $this>
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class, 'zone_id');
    }

    /**
     * The number a driver should call: the address-specific one when given,
     * otherwise the account's, which is what the design's "استخدام رقم الحساب"
     * toggle means.
     */
    public function callablePhone(): ?string
    {
        return $this->contact_phone ?: $this->user?->phone;
    }

    /**
     * Whether we serve this address at all: it is in a zone, and that zone
     * takes orders (`Zone::isServing()` — switched on, in a city switched on).
     * A pin outside every zone drawn on the map has no zone (`ZoneLocator`);
     * an address in a zone the owner switched off keeps it but is not served.
     * An order to or from either is refused (`OutOfCoverage`, the owner,
     * 2026-10-07) — which is what makes the zones' switch the way to choose
     * where the platform takes orders.
     *
     * A zone no laundry covers yet still counts — that order is accepted and
     * waits for an operator, because the gap is in our own setup, not in where
     * the customer lives. The one definition behind the refusal and the app's
     * `is_covered`, so the warning the app shows is the order the server refuses.
     * Reads `zone.city`: load it with a list.
     */
    public function isCovered(): bool
    {
        return $this->zone_id !== null && $this->zone?->isServing() === true;
    }

    /**
     * `isCovered()` in SQL — for «خارج التغطية»'s badge and sort order, so the
     * list, its count and the pill on each row cannot disagree. Change both
     * together.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCovered(Builder $query): Builder
    {
        return $query->whereNotNull($query->qualifyColumn('zone_id'))
            ->whereHas('zone', fn (Builder $zone) => $zone->serving());
    }
}
