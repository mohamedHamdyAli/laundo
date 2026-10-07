<?php

namespace App\Modules\Zone\Models;

use App\Modules\City\Models\City;
use App\Support\Geo\Polygon;
use App\Trait\DashboardModel;
use App\Trait\Scopes\Searchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An area inside a city: مدينة نصر, الدقي, الرحاب.
 *
 * The unit both assignment engines work in — a laundry declares the zones it
 * serves (laundry_zones) and, from P5, so does a driver.
 *
 * **Drawn on the map** (2026-09-29): `boundary` is the ring of corners the owner
 * drew, and a pin inside it is in this zone whatever the app picked
 * (ZoneLocator). A zone not drawn yet still works the old way — picked from a
 * list — so an install moves over one zone at a time.
 *
 * @property int $id
 * @property int $city_id
 * @property string|null $price_per_km
 * @property string|null $min_delivery_fee
 * @property array<int, array{0: float, 1: float}>|null $boundary
 * @property string|null $min_lat
 * @property string|null $max_lat
 * @property string|null $min_lng
 * @property string|null $max_lng
 * @property int $sort_order
 * @property string $status
 * @property-read mixed $name
 * @property-read City|null $city
 *
 * @method static Builder<static>|Zone serving()
 */
class Zone extends Model
{
    use DashboardModel;
    use Searchable;

    private ?string $polygonKey = null;

    private ?Polygon $parsedPolygon = null;

    protected $fillable = [
        'city_id', 'name', 'price_per_km', 'min_delivery_fee', 'sort_order', 'status',
        'boundary', 'min_lat', 'max_lat', 'min_lng', 'max_lng',
    ];

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
            'boundary' => 'array',
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

    /**
     * The drawing, as something that can answer «is this pin inside?» — null
     * for a zone not drawn yet.
     */
    public function polygon(): ?Polygon
    {
        // Parsed once per drawing, not once per question: checking a ring is
        // quadratic in its corners, and a redraw asks of every address near it.
        $key = $this->boundary ? md5((string) json_encode($this->boundary)) : null;

        if ($key !== $this->polygonKey) {
            $this->polygonKey = $key;
            $this->parsedPolygon = $key === null ? null : Polygon::fromArray($this->boundary);
        }

        return $this->parsedPolygon;
    }

    public function isDrawn(): bool
    {
        return $this->polygon() !== null;
    }

    /**
     * Whether orders are taken here: the zone is switched on, and so is its
     * city. The switch is how the owner chooses where the platform works
     * (2026-10-07) — an address in a zone switched off is refused like one in
     * no zone (`Address::isCovered()`), and taken again the moment it is
     * switched back on. Reads `city`; load `zone.city` with a list.
     */
    public function isServing(): bool
    {
        return $this->status === 'active' && $this->city?->status === 'active';
    }

    /**
     * `isServing()` in SQL. Change both together.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeServing(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), 'active')
            ->whereHas('city', fn (Builder $city) => $city->where('status', 'active'));
    }
}
