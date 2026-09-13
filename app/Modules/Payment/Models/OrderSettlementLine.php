<?php

namespace App\Modules\Payment\Models;

use App\Modules\Payment\Enums\CommissionBasis;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One charge on one settlement, frozen as it was applied.
 *
 * The name and terms are **copied, not referenced**: renaming a rule or moving
 * it from 10% to 12% next quarter must not restate what a laundry was already
 * charged. Same rule as copying prices onto an order.
 *
 * @property int $id
 * @property int $order_settlement_id
 * @property int|null $commission_rule_id
 * @property CommissionBasis $basis
 * @property string|null $rate
 * @property string $amount
 * @property Carbon|null $created_at
 * @property-read mixed $name
 * @property-read OrderSettlement|null $settlement
 *
 * @mixin \Eloquent
 */
class OrderSettlementLine extends Model
{
    protected $fillable = [
        'order_settlement_id', 'commission_rule_id',
        'name', 'basis', 'rate', 'amount',
    ];

    protected function casts(): array
    {
        return [
            'basis' => CommissionBasis::class,
            'rate' => 'decimal:2',
            'amount' => 'decimal:2',
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

    /**
     * @return BelongsTo<OrderSettlement, $this>
     */
    public function settlement(): BelongsTo
    {
        return $this->belongsTo(OrderSettlement::class, 'order_settlement_id');
    }

    /**
     * @return BelongsTo<CommissionRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(CommissionRule::class, 'commission_rule_id');
    }

    /**
     * The terms this line was charged at, in words.
     */
    public function explain(): string
    {
        return $this->basis->isFixed()
            ? moneyFormat($this->amount)
            : rtrim(rtrim(number_format((float) $this->rate, 2), '0'), '.').'%';
    }
}
