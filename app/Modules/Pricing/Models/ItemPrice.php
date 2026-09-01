<?php

namespace App\Modules\Pricing\Models;

use App\Modules\Item\Models\Item;
use App\Modules\Service\Models\Service;
use App\Trait\DashboardModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One cell of the price matrix: what one item costs under one service.
 *
 * Registered in config/dashboard.php purely so PermissionGenerator emits
 * `item_price.*`, which gates the grid editor. Prices are global — no tenant
 * scope, and laundries have none of these permissions.
 *
 * @property int $id
 * @property int $service_id
 * @property int $item_id
 * @property string $price
 */
class ItemPrice extends Model
{
    use DashboardModel;

    protected $fillable = ['service_id', 'item_id', 'price'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2'];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }
}
