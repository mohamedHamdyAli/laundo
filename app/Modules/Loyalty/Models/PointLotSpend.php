<?php

namespace App\Modules\Loyalty\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * «45 of the points spent on order 10244 came from the lot earned on order
 * 10190.»
 *
 * The join between a debit and the lots it ate. It exists so that points
 * given back go back with the expiry they had, rather than with a fresh one a
 * customer could farm by cancelling.
 *
 * @property int $id
 * @property int $debit_id
 * @property int $lot_id
 * @property int $points
 * @property-read PointTransaction|null $lot
 */
class PointLotSpend extends Model
{
    protected $fillable = ['debit_id', 'lot_id', 'points'];

    protected function casts(): array
    {
        return ['points' => 'integer'];
    }

    /**
     * @return BelongsTo<PointTransaction, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(PointTransaction::class, 'lot_id');
    }

    /**
     * @return BelongsTo<PointTransaction, $this>
     */
    public function debit(): BelongsTo
    {
        return $this->belongsTo(PointTransaction::class, 'debit_id');
    }
}
