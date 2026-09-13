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
 * One charge the platform makes on a laundry's order.
 *
 * A laundry may carry several, and **their results add together** — the owner's
 * decision. One number per laundry could only ever express one agreement, and a
 * real contract is «10% of the order, plus 5 EGP a job».
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
     * What this rule takes off one order of the given size.
     *
     * The basis is the order total before tax — tax is the state's money passing
     * through, and charging commission on it would have the platform billing a
     * laundry for the treasury's share.
     *
     * Never more than the basis: a flat charge of 20 on an order worth 12 would
     * otherwise hand the laundry a negative payout, and a bill that makes a
     * laundry owe money for having done work is a bill that is wrong rather
     * than harsh.
     */
    public function chargeOn(float $basis): float
    {
        $basis = max($basis, 0.0);

        $charge = $this->basis->isFixed()
            ? round((float) $this->amount, 2)
            : round($basis * $this->clampedRate() / 100, 2);

        return round(min(max($charge, 0.0), $basis), 2);
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
