<?php

namespace App\Modules\Payment\Repositories;

use App\Modules\Laundry\Models\Laundry;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Payment\Models\LaundryDeduction;
use App\Modules\Payment\Models\OrderSettlement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only place raw Eloquent for the laundry revenue screen lives.
 *
 * Three rules shape every query here.
 *
 * **The figures are read, never recomputed.** `order_settlements` already stores
 * `basis`, `commission_amount`, `laundry_amount` and `tax_amount` per order,
 * written by `SettlementService` against the rules in force on the day. Summing
 * those columns is the only way this screen can agree with the settlements
 * screen, the wallets and the invoices. Re-deriving a commission from today's
 * rate would quietly restate last quarter.
 *
 * **One date basis: `orders.created_at`.** Every count and every amount on a row
 * describes the same set of orders, so the row reconciles with itself — 13
 * orders, and the money those 13 produced. `RevenueReport` dates by `paid_at`
 * because it answers a different question («what arrived in this window»); mixing
 * the two here would show a count and a total that cover different orders.
 *
 * **No `withoutGlobalScopes()`.** `Order`, `OrderSettlement` and
 * `LaundryDeduction` are all tenant-scoped and `Laundry` scopes itself on `id`.
 * For a super admin `LaundryContext::currentId()` is null and the scopes are
 * no-ops, which is the whole bypass; for anybody confined to a laundry the same
 * queries narrow to their own row rather than leaking. Stripping the scopes here
 * would turn a safe degradation into a cross-tenant read.
 */
class LaundryRevenueRepository
{
    /**
     * Statuses that end an order without the laundry having earned from it.
     *
     * `returned` sits beside `cancelled` here and nowhere else in this class:
     * the pieces came back, so nothing was cleaned, even though the delivery fee
     * is still owed. That fee is the platform's and never enters the split, so
     * for a laundry's revenue the two outcomes are the same.
     *
     * @var array<int, string>
     */
    private const LOST = [
        OrderStatus::Cancelled->value,
        OrderStatus::Returned->value,
    ];

    /**
     * The laundries a page of the screen shows, newest first.
     *
     * Paginated over `Laundry` rather than over the aggregate, because the screen
     * is a list of laundries: a laundry with no orders in the window still has a
     * row, reading zero, which is itself the answer to «why has nothing come from
     * them this month».
     *
     * The concrete paginator, not `Contracts\Pagination\LengthAwarePaginator`:
     * the service maps the page through `through()`, which lives on
     * `AbstractPaginator` and is not on the contract.
     *
     * @return LengthAwarePaginator<int, Laundry>
     */
    public function laundries(?string $search, int $perPage = 15): LengthAwarePaginator
    {
        return Laundry::query()
            // The «BRANCHES» column of the screen this was modelled on. Laundo
            // has no branches; the nearest thing a laundry actually has is the
            // set of zones it has claimed, which is what decides the work it can
            // be given.
            ->withCount('zones')
            ->search($search, ['name', 'email', 'phone', 'city.name'])
            ->orderBy('id')
            ->paginate($perPage);
    }

    /**
     * Orders and settlements, added up per laundry, for the given laundries.
     *
     * One query, not two. `order_settlements.order_id` is unique, so the left
     * join cannot fan a row out and the order counts stay honest beside the
     * money. A left join rather than an inner one because an order can exist
     * before it has a settlement — an unassigned or still-unpriced order must
     * still be counted.
     *
     * Returned as a plain array rather than a collection: every caller only ever
     * looks one laundry up by id, and an array says the keys are laundry ids
     * where `Collection<int, object>` would be a claim `keyBy()` cannot keep.
     *
     * @param  array<int, int>  $laundryIds  empty means every laundry
     * @return array<int, object> keyed by laundry id
     */
    public function totals(Carbon $from, Carbon $to, array $laundryIds = []): array
    {
        $settlements = (new OrderSettlement)->getTable();
        $orders = (new Order)->getTable();

        $cancelled = OrderSettlement::CANCELLED;
        $settled = OrderSettlement::SETTLED;

        $lost = implode(',', array_map(fn ($s) => "'{$s}'", self::LOST));

        $query = Order::query()
            ->leftJoin($settlements, "{$settlements}.order_id", '=', "{$orders}.id")
            ->whereNotNull("{$orders}.laundry_id")
            ->whereBetween("{$orders}.created_at", [$from, $to])
            ->groupBy("{$orders}.laundry_id")
            ->select("{$orders}.laundry_id")
            ->selectRaw('count(*) as orders_count')
            ->selectRaw("sum(case when {$orders}.status = ? then 1 else 0 end) as completed_count", [OrderStatus::Completed->value])
            ->selectRaw("sum(case when {$orders}.status in ({$lost}) then 1 else 0 end) as cancelled_count")
            // What the customer actually handed over. `final_total` is what the
            // pieces came to once counted and `estimated_total` what they agreed
            // to; the coalesce is the same one every other money report uses.
            ->selectRaw("coalesce(sum(case when {$orders}.payment_status = 'paid' then coalesce({$orders}.final_total, {$orders}.estimated_total) else 0 end), 0) as user_paid")
            // Everything below comes off the settlement, never off the order. A
            // cancelled settlement owes nobody anything, so it contributes to
            // none of these.
            ->selectRaw("coalesce(sum(case when {$settlements}.status <> ? then {$settlements}.tax_amount else 0 end), 0) as tax_total", [$cancelled])
            ->selectRaw("coalesce(sum(case when {$settlements}.status <> ? then {$settlements}.commission_amount else 0 end), 0) as commission_total", [$cancelled])
            // The platform's other earning on the same order, and a separate
            // column because it is paid by somebody else: the commission comes
            // off the laundry, this comes off the customer. Summed into one
            // figure they would answer neither question.
            ->selectRaw("coalesce(sum(case when {$settlements}.status <> ? then {$settlements}.platform_fee_amount else 0 end), 0) as platform_fee_total", [$cancelled])
            ->selectRaw("coalesce(sum(case when {$settlements}.status <> ? then {$settlements}.laundry_amount else 0 end), 0) as entitled_total", [$cancelled])
            // What has actually been credited to the owner's wallet, as against
            // what is merely recorded. The gap between these two columns is the
            // question the screen exists to answer.
            ->selectRaw("coalesce(sum(case when {$settlements}.status = ? then {$settlements}.laundry_amount else 0 end), 0) as settled_total", [$settled])
            ->selectRaw("coalesce(sum(case when {$settlements}.status <> ? then {$settlements}.basis else 0 end), 0) as basis_total", [$cancelled]);

        if ($laundryIds !== []) {
            $query->whereIn("{$orders}.laundry_id", $laundryIds);
        }

        return $this->keyByLaundry($query->get()->all());
    }

    /**
     * The same aggregation with the per-laundry grouping taken off — the cards.
     *
     * Deliberately its own query rather than a sum of the page above: the cards
     * describe the whole window, and adding up fifteen rows of a paginated list
     * would make them describe page one.
     *
     * @return object{orders_count: int, user_paid: float, tax_total: float, commission_total: float, platform_fee_total: float, entitled_total: float, settled_total: float}
     */
    public function summary(Carbon $from, Carbon $to): object
    {
        $row = array_reduce($this->totals($from, $to), function (array $carry, object $row): array {
            foreach ($carry as $key => $value) {
                $carry[$key] = $value + (float) $row->getAttribute($key);
            }

            return $carry;
        }, [
            'orders_count' => 0.0,
            'user_paid' => 0.0,
            'tax_total' => 0.0,
            'commission_total' => 0.0,
            'platform_fee_total' => 0.0,
            'entitled_total' => 0.0,
            'settled_total' => 0.0,
        ]);

        $row['orders_count'] = (int) $row['orders_count'];

        return (object) $row;
    }

    /**
     * Deductions standing against each laundry inside the window.
     *
     * Dated by when the deduction was recorded, which is the only date it has.
     * A reversed one contributes nothing — it is kept for the record, not for the
     * arithmetic.
     *
     * @param  array<int, int>  $laundryIds  empty means every laundry
     * @return array<int, object> keyed by laundry id
     */
    public function deductions(Carbon $from, Carbon $to, array $laundryIds = []): array
    {
        $query = LaundryDeduction::query()
            ->applied()
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('laundry_id')
            ->select('laundry_id')
            ->selectRaw('coalesce(sum(amount), 0) as deducted_total')
            ->selectRaw('count(*) as deductions_count');

        if ($laundryIds !== []) {
            $query->whereIn('laundry_id', $laundryIds);
        }

        return $this->keyByLaundry($query->get()->all());
    }

    /**
     * The reasons themselves, most recent first, for the rows on screen.
     *
     * A separate read from the totals because the screen shows the newest reason
     * under the amount and the export lists them all: an aggregate can carry a
     * sum or a string, not both, and `GROUP_CONCAT` is spelled differently on
     * MariaDB and SQLite.
     *
     * Grouped by hand rather than with `groupBy('laundry_id')`, which keys the
     * result by whatever the driver hands back — a string on one connection and
     * an int on another — and then no caller can look a laundry up by its id
     * without guessing which.
     *
     * @param  array<int, int>  $laundryIds
     * @return array<int, array<int, LaundryDeduction>> keyed by laundry id
     */
    public function reasons(Carbon $from, Carbon $to, array $laundryIds): array
    {
        if ($laundryIds === []) {
            return [];
        }

        $rows = LaundryDeduction::query()
            ->applied()
            ->whereIn('laundry_id', $laundryIds)
            ->whereBetween('created_at', [$from, $to])
            ->latest('id')
            ->get();

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(int) $row->laundry_id][] = $row;
        }

        return $grouped;
    }

    /**
     * Re-key aggregate rows by their `laundry_id`, as an int.
     *
     * @param  array<int, object>  $rows
     * @return array<int, object>
     */
    private function keyByLaundry(array $rows): array
    {
        $keyed = [];

        foreach ($rows as $row) {
            $keyed[(int) $row->getAttribute('laundry_id')] = $row;
        }

        return $keyed;
    }

    /**
     * Every laundry that had an order in the window, for the «laundries» card.
     *
     * Counted off the orders rather than off the `laundries` table: «7 vendors»
     * on the screen this follows means seven that traded, not seven that exist.
     */
    public function activeLaundryCount(Carbon $from, Carbon $to): int
    {
        return Order::query()
            ->whereNotNull('laundry_id')
            ->whereBetween('created_at', [$from, $to])
            ->distinct()
            ->count(DB::raw('laundry_id'));
    }

    public function createDeduction(array $data): LaundryDeduction
    {
        return LaundryDeduction::create($data);
    }

    /**
     * Every deduction standing against one laundry, for the reversal.
     *
     * @return Builder<LaundryDeduction>
     */
    public function appliedFor(int $laundryId): Builder
    {
        return LaundryDeduction::query()->applied()->where('laundry_id', $laundryId);
    }

    public function findDeduction(int $id): LaundryDeduction
    {
        return LaundryDeduction::findOrFail($id);
    }
}
