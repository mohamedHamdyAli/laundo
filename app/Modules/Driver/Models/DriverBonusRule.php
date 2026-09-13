<?php

namespace App\Modules\Driver\Models;

use App\Modules\Driver\Enums\BonusBasis;
use App\Trait\DashboardModel;
use App\Trait\Scopes\Searchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The terms a driver's bonus is paid on.
 *
 * A rule is shared: almost every driver is on the same terms, and terms they
 * share can be changed once. A rate copied onto three hundred profiles is three
 * hundred rows to find on the day the number moves.
 *
 * @property int $id
 * @property BonusBasis $basis
 * @property string|null $amount
 * @property string|null $rate
 * @property string|null $min_on_time_rate
 * @property string|null $min_delivery_rating
 * @property int|null $max_failed_tasks
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read mixed $name
 * @property-read Collection<int, DriverBonusTier> $tiers
 *
 * @method static Builder<static>|DriverBonusRule newModelQuery()
 * @method static Builder<static>|DriverBonusRule newQuery()
 * @method static Builder<static>|DriverBonusRule query()
 * @method static Builder<static>|DriverBonusRule search(?string $search, array $columns = [])
 *
 * @mixin \Eloquent
 */
class DriverBonusRule extends Model
{
    use DashboardModel;
    use Searchable;

    protected $fillable = [
        'name', 'basis', 'amount', 'rate',
        'min_on_time_rate', 'min_delivery_rating', 'max_failed_tasks',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'basis' => BonusBasis::class,
            'amount' => 'decimal:2',
            'rate' => 'decimal:2',
            'min_on_time_rate' => 'decimal:2',
            'min_delivery_rating' => 'decimal:2',
            'max_failed_tasks' => 'integer',
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
     * The monthly targets, cheapest first.
     *
     * Ordered here rather than at the call sites because the calculator walks
     * them to find the highest one reached, and a list in database order would
     * make that answer depend on the order somebody happened to type them in.
     *
     * @return HasMany<DriverBonusTier, $this>
     */
    public function tiers(): HasMany
    {
        return $this->hasMany(DriverBonusTier::class, 'driver_bonus_rule_id')
            ->orderBy('min_orders');
    }

    /**
     * @return HasMany<DriverProfile, $this>
     */
    public function profiles(): HasMany
    {
        return $this->hasMany(DriverProfile::class, 'bonus_rule_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * True when this rule pays anything as the driver works.
     *
     * A rule can be monthly-only: tiers with no immediate amount is a perfectly
     * ordinary arrangement — «مرتبك بره، والمكافأة آخر الشهر».
     */
    public function hasImmediateBonus(): bool
    {
        return $this->basis->isFlat()
            ? (float) $this->amount > 0
            : (float) $this->rate > 0;
    }

    /**
     * True when this rule has any monthly target at all.
     */
    public function hasMonthlyBonus(): bool
    {
        return $this->tiers()->exists();
    }

    /**
     * The immediate terms in words, for a table cell.
     */
    public function explainImmediate(): string
    {
        if (! $this->hasImmediateBonus()) {
            return __('No immediate bonus');
        }

        if ($this->basis->isFlat()) {
            return moneyFormat($this->amount).' · '.__($this->basis->short());
        }

        return rtrim(rtrim(number_format((float) $this->rate, 2), '0'), '.').'% · '
            .__($this->basis->short());
    }

    /**
     * The gates in words, or null when nothing is gated.
     *
     * @return array<int, string>
     */
    public function explainGates(): array
    {
        $parts = [];

        if ($this->min_on_time_rate !== null) {
            $parts[] = __('On time :rate%+', [
                'rate' => rtrim(rtrim(number_format((float) $this->min_on_time_rate, 2), '0'), '.'),
            ]);
        }

        if ($this->min_delivery_rating !== null) {
            $parts[] = __('Rating :rating+', [
                'rating' => rtrim(rtrim(number_format((float) $this->min_delivery_rating, 2), '0'), '.'),
            ]);
        }

        if ($this->max_failed_tasks !== null) {
            $parts[] = __('Max :count failed', ['count' => $this->max_failed_tasks]);
        }

        return $parts;
    }
}
