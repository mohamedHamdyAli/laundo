<?php

namespace App\Modules\ItemCategory\Models;

use App\Modules\Item\Models\Item;
use App\Trait\DashboardModel;
use App\Trait\Scopes\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Groups items for display: القمصان، التيشيرتات، الملابس العلوية…
 *
 * @property int $id
 * @property int $sort_order
 * @property string $status
 * @property-read mixed $name
 */
class ItemCategory extends Model
{
    use DashboardModel;
    use Searchable;

    protected $fillable = ['name', 'image', 'sort_order', 'status'];

    protected function asJson($value, $flags = 0)
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    public function getNameAttribute($value)
    {
        return json_decode((string) $value);
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'item_category_id')->orderBy('sort_order');
    }
}
