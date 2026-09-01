<?php

namespace App\Modules\Order\Models;

use App\Modules\User\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A customer's «لدي استفسار عن السعر».
 *
 * @property int $id
 * @property int $order_id
 * @property int $user_id
 * @property string $message
 * @property string|null $answer
 * @property Carbon|null $answered_at
 * @property int|null $answered_by
 * @property Carbon|null $created_at
 *
 * @method static Builder<static>|OrderPriceQuery open()
 */
class OrderPriceQuery extends Model
{
    protected $fillable = ['order_id', 'user_id', 'message', 'answer', 'answered_at', 'answered_by'];

    protected function casts(): array
    {
        return ['answered_at' => 'datetime'];
    }

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
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answered_by');
    }

    /**
     * Questions nobody has answered yet — the only view of this table that
     * matters day to day.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('answered_at');
    }

    public function isAnswered(): bool
    {
        return $this->answered_at !== null;
    }
}
