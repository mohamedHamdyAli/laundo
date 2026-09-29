<?php

namespace App\Modules\Pricing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One price as a permanent rise found it and left it.
 *
 * @property int $id
 * @property int $price_change_id
 * @property int $item_id
 * @property int $service_id
 * @property string $price_before
 * @property string $price_after
 */
class PriceChangeItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['price_change_id', 'item_id', 'service_id', 'price_before', 'price_after'];

    protected function casts(): array
    {
        return [
            'price_before' => 'decimal:2',
            'price_after' => 'decimal:2',
        ];
    }
}
