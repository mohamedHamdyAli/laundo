<?php

namespace App\Modules\Service\Models;

use App\Modules\Pricing\Models\ItemPrice;
use App\Trait\DashboardModel;
use App\Trait\Scopes\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A service the customer picks in step 2 of the order wizard: غسيل وكي، كي فقط،
 * تنظيف جاف، غسيل المفروشات.
 *
 * Prices are global and live in item_prices, keyed on (service, item). A service
 * with pricing_mode = quote carries no prices at all — it is quoted after the
 * pieces are inspected.
 *
 * @property int $id
 * @property string $pricing_mode
 * @property int|null $duration_min
 * @property int|null $duration_max
 * @property string $duration_unit
 * @property int $sort_order
 * @property string $status
 * @property-read mixed $name
 * @property-read mixed $description
 */
class Service extends Model
{
    use DashboardModel;
    use Searchable;

    protected $fillable = [
        'name',
        'description',
        'image',
        'pricing_mode',
        'duration_min',
        'duration_max',
        'duration_unit',
        'sort_order',
        'status',
    ];

    protected function asJson($value, $flags = 0)
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    public function getNameAttribute($value)
    {
        return json_decode((string) $value);
    }

    public function getDescriptionAttribute($value)
    {
        return json_decode((string) $value);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ItemPrice::class, 'service_id');
    }

    /**
     * True when this service is priced per piece and therefore appears in the
     * price grid.
     */
    public function isPerItem(): bool
    {
        return $this->pricing_mode === 'per_item';
    }

    /**
     * The turnaround as the design writes it: "24–48", "24" when the range
     * collapses, or null when unset.
     */
    public function durationLabel(): ?string
    {
        if ($this->duration_min === null && $this->duration_max === null) {
            return null;
        }

        $min = $this->duration_min ?? $this->duration_max;
        $max = $this->duration_max ?? $this->duration_min;

        return $min === $max ? (string) $min : "{$min}–{$max}";
    }
}
