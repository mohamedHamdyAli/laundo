<?php

namespace App\Modules\Order\Models;

use App\Modules\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A photo attached to an order.
 *
 * @property int $id
 * @property int $order_id
 * @property string $type
 * @property string $path
 * @property int|null $uploaded_by
 */
class OrderMedia extends Model
{
    protected $table = 'order_media';

    protected $fillable = ['order_id', 'type', 'path', 'uploaded_by'];

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function url(): string
    {
        return getImageassetUrl($this->path);
    }
}
