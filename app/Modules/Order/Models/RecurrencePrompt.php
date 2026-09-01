<?php

namespace App\Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One asking of «محتاج تغسل النهاردة؟».
 *
 * @property int $id
 * @property int $recurrence_id
 * @property Carbon $prompted_for
 * @property Carbon|null $prompted_at
 * @property string|null $answer
 * @property Carbon|null $answered_at
 * @property int|null $order_id
 * @property-read OrderRecurrence|null $recurrence
 */
class RecurrencePrompt extends Model
{
    protected $fillable = [
        'recurrence_id', 'prompted_for', 'prompted_at', 'answer', 'answered_at', 'order_id',
    ];

    protected function casts(): array
    {
        return [
            'prompted_for' => 'date',
            'prompted_at' => 'datetime',
            'answered_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<OrderRecurrence, $this>
     */
    public function recurrence(): BelongsTo
    {
        return $this->belongsTo(OrderRecurrence::class, 'recurrence_id');
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function isAnswered(): bool
    {
        return $this->answer !== null;
    }
}
