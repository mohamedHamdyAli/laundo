<?php

namespace App\Modules\Zone\Models;

use App\Modules\City\Models\City;
use App\Trait\DashboardModel;
use App\Trait\Scopes\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A named area inside a city: مدينة نصر, الدقي, الرحاب.
 *
 * The unit both assignment engines work in — a laundry declares the zones it
 * serves (laundry_zones) and, from P5, so does a driver.
 *
 * @property int $id
 * @property int $city_id
 * @property string|null $price_per_km
 * @property string|null $min_delivery_fee
 * @property int $sort_order
 * @property string $status
 * @property-read mixed $name
 * @property-read City|null $city
 */
class Zone extends Model
{
    use DashboardModel;
    use Searchable;

    protected $fillable = ['city_id', 'name', 'price_per_km', 'min_delivery_fee', 'sort_order', 'status'];

    /**
     * Both nullable, and deliberately so: an unpriced zone makes
     * DeliveryFeeCalculator report `zone_has_no_rate` instead of inventing a
     * free delivery.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_per_km' => 'decimal:2',
            'min_delivery_fee' => 'decimal:2',
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

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_id');
    }
}
