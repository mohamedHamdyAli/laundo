<?php

namespace App\Modules\Order\Models;

use App\Modules\Item\Models\Item;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One priced line on an order.
 *
 * `unit_price` is a copy taken when the order was placed, never a live lookup —
 * see the migration for why.
 *
 * @property int $id
 * @property int $order_id
 * @property int $item_id
 * @property string $phase
 * @property int $qty
 * @property string $unit_price
 * @property string $line_total
 */
class OrderItem extends Model
{
    protected $fillable = ['order_id', 'item_id', 'phase', 'qty', 'unit_price', 'line_total'];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }
}
