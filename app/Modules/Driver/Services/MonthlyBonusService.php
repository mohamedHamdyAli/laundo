<?php

namespace App\Modules\Driver\Services;

use App\Modules\Driver\Models\DriverBonusAward;
use App\Modules\Driver\Models\DriverBonusRule;
use App\Modules\Driver\Models\DriverProfile;
use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\OrderRating;
use App\Modules\Order\Models\OrderTask;
use App\Modules\User\Models\User;
use App\Modules\Wallet\Enums\TransactionReason;
use App\Modules\Wallet\Services\WalletService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * «مكافأة آخر الشهر» — what a driver earned for the month, and whether they keep it.
 *
 * Two things this class exists to get right.
 *
 * **A bonus paid purely on volume pays a driver to rush**, and rushing is
 * damaged clothes, wrong addresses and a customer who does not order again. So
 * the tiers are counted on orders delivered, and then three gates decide whether
 * the driver keeps what the count earned. All three are measured from columns
 * the application has always written and nothing has ever read:
 * `order_tasks.due_at`, `order_ratings.delivery`, and the failed-task count.
 *
 * **Nothing here pays itself.** `computeFor()` writes a row at `due` and moves
 * no money; `approve()` is a separate act by a person. The same rule as refunds
 * — «الاسترداد الموافق عليه بس هو اللي بيتصرف» — and for the same reason: a
 * monthly payout that runs on a schedule is a wrong payment made in the month
 * nobody was looking. It also means a gate that misfires costs a conversation
 * rather than a clawback.
 *
 * The four measurements are **stored on the award**, not recomputed on read. A
 * rating that arrives in November must not restate an October that has been
 * paid.
 */
class MonthlyBonusService
{
    public function __construct(private readonly WalletService $wallets) {}

    /**
     * The period key for a date: 'YYYY-MM'.
     */
    public function period(?CarbonImmutable $at = null): string
    {
        return ($at ?? CarbonImmutable::now())->format('Y-m');
    }

    /**
     * Turn a period key back into its month, or null if it is not one.
     *
     * Validated rather than trusted: the period arrives from a query string, and
     * `?period=0000-99` reaching a date parser is how a screen throws instead of
     * saying «that is not a month».
     */
    public function parsePeriod(?string $period): ?CarbonImmutable
    {
        if ($period === null || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            return null;
        }

        [$year, $month] = array_map('intval', explode('-', $period));

        // A four-digit year still allows 0001. Bounded to something a laundry
        // could plausibly be operating in, so a pasted URL cannot walk the
        // screen back to the first century.
        if ($year < 2020 || $year > 2100) {
            return null;
        }

        return CarbonImmutable::create($year, $month, 1)->startOfMonth();
    }

    /**
     * Every driver who is on an active rule, with the rule loaded.
     *
     * A driver on no rule is not merely worth zero — they are not part of this
     * screen at all, the same way a laundry with no orders is not a settlement.
     *
     * @return Collection<int, DriverProfile>
     */
    public function eligibleProfiles(): Collection
    {
        return DriverProfile::query()
            ->whereNotNull('bonus_rule_id')
            ->whereHas('bonusRule', fn ($query) => $query->where('status', 'active'))
            ->with(['bonusRule.tiers', 'user:id,name,phone'])
            ->get();
    }

    /**
     * What one driver did in one month.
     *
     * Counted on **completed legs**, because that is what the driver was
     * personally responsible for — an order can pass through two drivers, and
     * counting whole orders would pay both of them for one delivery.
     *
     * @return array{orders_count: int, on_time_rate: float|null, avg_delivery_rating: float|null, failed_tasks: int}
     */
    public function measure(int $driverId, CarbonImmutable $month): array
    {
        $from = $month->startOfMonth();
        $to = $month->endOfMonth();

        // Orders delivered: the final handover, one per order, so the count
        // matches what an operator means by «وصّل ١٠٠ طلب».
        $delivered = OrderTask::withoutGlobalScopes()
            ->where('driver_id', $driverId)
            ->where('type', TaskType::DeliverToCustomer->value)
            ->where('status', TaskStatus::Completed->value)
            ->whereBetween('completed_at', [$from, $to]);

        $ordersCount = (clone $delivered)->count();

        // On time: of the legs that had a deadline at all, how many met it.
        // Legs with no `due_at` are excluded rather than counted as met — a leg
        // nobody scheduled is not evidence of punctuality either way.
        $timed = OrderTask::withoutGlobalScopes()
            ->where('driver_id', $driverId)
            ->where('status', TaskStatus::Completed->value)
            ->whereNotNull('due_at')
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$from, $to]);

        $timedCount = (clone $timed)->count();
        $onTimeCount = (clone $timed)->whereColumn('completed_at', '<=', 'due_at')->count();

        // Null, not 100: a driver with no scheduled legs has no on-time record,
        // and treating that as perfect would let a gate be passed by doing
        // nothing.
        $onTimeRate = $timedCount > 0
            ? round($onTimeCount / $timedCount * 100, 2)
            : null;

        $failed = OrderTask::withoutGlobalScopes()
            ->where('driver_id', $driverId)
            ->where('status', TaskStatus::Failed->value)
            ->whereBetween('updated_at', [$from, $to])
            ->count();

        // The delivery score only — never the overall one. A driver must not
        // lose their bonus because a laundry returned a shirt badly ironed.
        $orderIds = (clone $delivered)->pluck('order_id');

        $avgRating = $orderIds->isEmpty() ? null : OrderRating::withoutGlobalScopes()
            ->whereIn('order_id', $orderIds)
            ->whereNotNull('delivery')
            ->avg('delivery');

        return [
            'orders_count' => $ordersCount,
            'on_time_rate' => $onTimeRate,
            'avg_delivery_rating' => $avgRating === null ? null : round((float) $avgRating, 2),
            'failed_tasks' => $failed,
        ];
    }

    /**
     * Which gates this month's numbers fail, in the operator's words.
     *
     * A null measurement never fails a gate. A driver with no ratings yet has
     * not fallen below a rating threshold — they have no rating — and refusing a
     * bonus for an absence of evidence is how a new driver is told the scheme is
     * rigged.
     *
     * @param  array{orders_count: int, on_time_rate: float|null, avg_delivery_rating: float|null, failed_tasks: int}  $stats
     * @return array<int, string>
     */
    public function gateFailures(DriverBonusRule $rule, array $stats): array
    {
        $failures = [];

        if ($rule->min_on_time_rate !== null
            && $stats['on_time_rate'] !== null
            && $stats['on_time_rate'] < (float) $rule->min_on_time_rate) {
            $failures[] = 'on_time';
        }

        if ($rule->min_delivery_rating !== null
            && $stats['avg_delivery_rating'] !== null
            && $stats['avg_delivery_rating'] < (float) $rule->min_delivery_rating) {
            $failures[] = 'rating';
        }

        if ($rule->max_failed_tasks !== null
            && $stats['failed_tasks'] > $rule->max_failed_tasks) {
            $failures[] = 'failed_tasks';
        }

        return $failures;
    }

    /**
     * The highest tier the count reaches, or null.
     *
     * **Highest reached, never the sum.** A driver who delivered 160 against
     * tiers at 100 and 150 is paid the 150 tier once. That is what «١٥٠ طلب →
     * ٩٠٠ ج» means: the price of the level, not an increment on the one below.
     */
    public function tierFor(DriverBonusRule $rule, int $ordersCount): ?object
    {
        return $rule->tiers
            ->filter(fn ($tier) => $ordersCount >= $tier->min_orders)
            ->sortByDesc('min_orders')
            ->first();
    }

    /**
     * Work out one driver's month and record it, without moving anything.
     *
     * Idempotent on (driver, period), and re-computed while the row is still
     * `due`: a month in progress changes every day. An **approved** row is
     * frozen — money moved against those four numbers.
     */
    public function computeFor(DriverProfile $profile, CarbonImmutable $month): ?DriverBonusAward
    {
        $rule = $profile->bonusRule;

        if ($rule === null || ! $rule->isActive()) {
            return null;
        }

        $period = $this->period($month);
        $driverId = $profile->user_id;

        $existing = DriverBonusAward::where('driver_id', $driverId)
            ->where('period', $period)
            ->first();

        if ($existing && ! $existing->isRecomputable()) {
            return $existing;
        }

        $stats = $this->measure($driverId, $month);
        $failures = $this->gateFailures($rule, $stats);
        $tier = $this->tierFor($rule, $stats['orders_count']);

        // A gate that fails pays nothing, however many orders were delivered.
        $amount = ($failures === [] && $tier !== null) ? (float) $tier->amount : 0.0;

        $attributes = $stats + [
            'driver_bonus_rule_id' => $rule->id,
            'tier_min_orders' => $tier?->min_orders,
            'amount' => $amount,
            'gate_failures' => $failures === [] ? null : $failures,
            'status' => DriverBonusAward::DUE,
        ];

        if ($existing) {
            $existing->update($attributes);

            return $existing->refresh();
        }

        return DriverBonusAward::create($attributes + [
            'driver_id' => $driverId,
            'period' => $period,
        ]);
    }

    /**
     * Recompute a whole month.
     *
     * @return int how many awards were written or refreshed
     */
    public function computePeriod(CarbonImmutable $month): int
    {
        $written = 0;

        foreach ($this->eligibleProfiles() as $profile) {
            if ($this->computeFor($profile, $month) !== null) {
                $written++;
            }
        }

        return $written;
    }

    /**
     * Pay it. The only place a monthly bonus becomes money.
     *
     * @throws RuntimeException
     */
    public function approve(DriverBonusAward $award, ?User $actor = null, ?string $note = null): DriverBonusAward
    {
        if (! $award->isDue()) {
            // Not an assertion about intent — a guard against the double click
            // and the replayed POST, either of which would otherwise credit a
            // driver twice for one month.
            throw new RuntimeException('award_not_due');
        }

        if ((float) $award->amount <= 0) {
            throw new RuntimeException('award_is_zero');
        }

        $driver = User::find($award->driver_id);

        if (! $driver) {
            throw new RuntimeException('driver_not_found');
        }

        return DB::transaction(function () use ($award, $driver, $actor, $note) {
            $this->wallets->credit(
                $driver,
                (float) $award->amount,
                TransactionReason::Bonus,
                $award,
                __('Monthly bonus for :period', ['period' => $award->period]),
                $actor,
            );

            $award->update([
                'status' => DriverBonusAward::APPROVED,
                'approved_at' => now(),
                'approved_by' => $actor?->id,
                'note' => $note,
            ]);

            return $award->refresh();
        });
    }

    /**
     * Decline it, with a reason.
     *
     * Rejected rather than deleted: «why did I not get September» is a question
     * that has to have an answer, and a row that was removed cannot answer it.
     */
    public function reject(DriverBonusAward $award, ?User $actor = null, ?string $note = null): DriverBonusAward
    {
        if (! $award->isDue()) {
            throw new RuntimeException('award_not_due');
        }

        $award->update([
            'status' => DriverBonusAward::REJECTED,
            'approved_at' => now(),
            'approved_by' => $actor?->id,
            'note' => $note,
        ]);

        return $award->refresh();
    }
}
