<?php

namespace App\Modules\Item\Models;

use App\Modules\ItemCategory\Models\ItemCategory;
use App\Modules\Pricing\Models\ItemPrice;
use App\Trait\DashboardModel;
use App\Trait\Scopes\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The priced unit: قميص على شماعة، قميص مطوي، قميص كتان…
 *
 * Carries no price of its own — the price depends on the service applied to it.
 *
 * @property int $id
 * @property int $item_category_id
 * @property int $sort_order
 * @property string $status
 * @property-read mixed $name
 */
class Item extends Model
{
    use DashboardModel;
    use Searchable;

    protected $fillable = ['item_category_id', 'name', 'image', 'sort_order', 'status'];

    protected function asJson($value, $flags = 0)
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    public function getNameAttribute($value)
    {
        return json_decode((string) $value);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'item_category_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ItemPrice::class, 'item_id');
    }
}
