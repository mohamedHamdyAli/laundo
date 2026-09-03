<?php

namespace App\Modules\Address\Models;

use App\Modules\City\Models\City;
use App\Modules\User\Models\User;
use App\Modules\Zone\Models\Zone;
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
}
