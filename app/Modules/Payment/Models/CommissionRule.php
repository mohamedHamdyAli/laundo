<?php

namespace App\Modules\Payment\Models;

use App\Modules\Laundry\Models\Laundry;
use App\Modules\Payment\Enums\CommissionBasis;
use App\Trait\DashboardModel;
use App\Trait\Scopes\Searchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * The share of the washing a laundry receives.
 *
 * **The laundry's share, not the platform's cut.** For the life of the project
 * the number here was what the platform took; the client turned it round —
 * «المغسلة هي اللي هتاخد النسبة» — so 10 now means the laundry is paid 10 in the
 * hundred and the platform keeps 90. The table kept its name because every
 * screen, permission and route already carried it; the migration that flipped
 * the meaning rewrote every stored rate so no laundry's payout moved.
 *
 * **One active rule per laundry, percentage only.** They used to stack and
 * could be a fixed amount per order; both went with the reversal. A fixed rule
 * still in the table is history — it cannot be switched back on.
 *
 * @property int $id
 * @property CommissionBasis $basis
 * @property string|null $rate
 * @property string|null $amount
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read mixed $name
 * @property-read Collection<int, Laundry> $laundries
 *
 * @method static Builder<static>|CommissionRule newModelQuery()
 * @method static Builder<static>|CommissionRule newQuery()
 * @method static Builder<static>|CommissionRule query()
 * @method static Builder<static>|CommissionRule active()
 * @method static Builder<static>|CommissionRule search(?string $search, array $columns = [])
 *
 * @mixin \Eloquent
 */
class CommissionRule extends Model
{
    use DashboardModel;
    use Searchable;

    protected $fillable = ['name', 'basis', 'rate', 'amount', 'status'];

    protected function casts(): array
    {
        return [
            'basis' => CommissionBasis::class,
            'rate' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    /**
     * Keep Arabic readable in the column instead of \uXXXX escapes.
     */
    protected function asJson($value, $flags = 0)
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Translatable: returns a stdClass, so it is $rule->name->ar — never
     * $rule->name['ar']. Matches every other translatable column here.
     */
    public function getNameAttribute($value)
    {
        return json_decode((string) $value);
    }

    /**
     * The laundries charged under this rule.
     *
     * `withoutGlobalScopes` is NOT applied here: reading a rule's laundries as a
     * laundry owner would correctly show only their own — but no laundry screen
     * reaches this relation, and the admin ones run unscoped anyway.
     *
     * @return BelongsToMany<Laundry, $this>
     */
    public function laundries(): BelongsToMany
    {
        return $this->belongsToMany(Laundry::class, 'commission_rule_laundry')->withTimestamps();
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * The rate, clamped. The column is a decimal but the form is a text box.
     */
    public function clampedRate(): float
    {
        return round(max(min((float) $this->rate, 100.0), 0.0), 2);
    }

    /**
     * The terms in words, for a table cell and for the settlement line.
     */
    public function explain(): string
    {
        if ($this->basis->isFixed()) {
            return moneyFormat($this->amount);
        }

        return rtrim(rtrim(number_format($this->clampedRate(), 2), '0'), '.').'%';
    }
}
