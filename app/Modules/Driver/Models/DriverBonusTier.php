<?php

namespace App\Modules\Driver\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One monthly target on a rule: «وصّل :min طلب → :amount».
 *
 * @property int $id
 * @property int $driver_bonus_rule_id
 * @property int $min_orders
 * @property string $amount
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read DriverBonusRule|null $rule
 *
 * @mixin \Eloquent
 */
class DriverBonusTier extends Model
{
    protected $fillable = ['driver_bonus_rule_id', 'min_orders', 'amount'];

    protected function casts(): array
    {
        return [
            'min_orders' => 'integer',
            'amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<DriverBonusRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(DriverBonusRule::class, 'driver_bonus_rule_id');
    }
}
