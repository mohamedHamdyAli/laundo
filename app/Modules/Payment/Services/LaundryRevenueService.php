<?php

namespace App\Modules\Payment\Services;

use App\Modules\Laundry\Models\Laundry;
use App\Modules\Payment\Data\RevenueWindow;
use App\Modules\Payment\Models\LaundryDeduction;
use App\Modules\Payment\Repositories\LaundryRevenueRepository;
use App\Modules\User\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * What each laundry brought in, what the platform took, and what is left.
 *
 * The screen answers one question per row — «هذه المغسلة، كام طلب، العميل دفع
 * كام، المنصة أخدت كام، وباقي لها كام» — and every figure in that sentence is
 * read off a row somebody already wrote: `orders` for the counts and what was
 * collected, `order_settlements` for the division, `laundry_deductions` for
 * what has since been taken back.
 *
 * **Nothing here recomputes a commission.** `SettlementService` did that at the
 * moment the price was agreed, against the rules in force that day, and stored
 * every component. Re-deriving it from today's rate is how a closed month
 * quietly restates itself — and it would put this screen at odds with the
 * settlements list, the wallets and the invoices, all of which read the stored
 * figures.
 *
 * **«مستحق» and «محصّل» are two columns, not one.** A settlement is recorded
 * `pending` when the price is agreed and only becomes `settled` when the order
 * completes and both wallets move. A laundry looking at a month wants both: what
 * the work came to, and how much of it has actually arrived. Collapsing them
 * would hide the gap, which is the thing worth looking at.
 */
class LaundryRevenueService
{
    public function __construct(private readonly LaundryRevenueRepository $repository) {}

    /**
     * Everything the screen renders.
     *
     * Named for the convention the panel's CRUD services follow, though this one
     * assembles a report rather than a record: the controller hands the result
     * straight to the view, and the partial that draws the rows is re-rendered
     * on its own by `search()`.
     *
     * @return array<string, mixed>
     */
    public function shredData(Request $request): array
    {
        $window = RevenueWindow::fromRequest($request);

        // `get('query')`, never `$request->query` — that is Symfony's own
        // ParameterBag property and hands back the bag rather than the term.
        $search = $request->get('query');

        $laundries = $this->repository->laundries($search);

        $ids = collect($laundries->items())->pluck('id')->map(fn ($id) => (int) $id)->all();

        $totals = $this->repository->totals($window->from, $window->to, $ids);
        $deductions = $this->repository->deductions($window->from, $window->to, $ids);
        $reasons = $this->repository->reasons($window->from, $window->to, $ids);

        // Standing deductions, ignoring the window: the ↺ action withdraws a
        // claim rather than a month, so the button has to know whether there is
        // anything to withdraw even when the window in view predates it.
        $standing = $this->standingCounts($ids);

        return [
            'window' => $window,
            'search' => $search,
            'years' => RevenueWindow::years(),
            'rows' => $laundries->through(
                fn (Laundry $laundry) => $this->row($laundry, $totals, $deductions, $reasons, $standing)
            ),
            'summary' => $this->summary($window),
        ];
    }

    /**
     * The five cards.
     *
     * Over the whole window, not over the page. Adding up fifteen visible rows
     * would make the headline figures describe page one, which is the sort of
     * wrong number nobody checks because it is not obviously wrong.
     *
     * @return array<string, float|int>
     */
    public function summary(RevenueWindow $window): array
    {
        $totals = $this->repository->summary($window->from, $window->to);
        $deducted = array_sum(array_map(
            fn (object $row) => (float) $row->getAttribute('deducted_total'),
            $this->repository->deductions($window->from, $window->to)
        ));

        $entitled = (float) $totals->entitled_total;

        return [
            'orders' => (int) $totals->orders_count,
            'user_paid' => round((float) $totals->user_paid, 2),
            'tax' => round((float) $totals->tax_total, 2),
            'commission' => round((float) $totals->commission_total, 2),
            'platform_fee' => round((float) $totals->platform_fee_total, 2),
            // What the laundries are owed once what has been taken back is
            // taken off. Never below zero on the card: a negative headline reads
            // as a system fault rather than as an over-deduction, and the row it
            // came from says so precisely.
            'laundries_receive' => round(max($entitled - $deducted, 0), 2),
            'deducted' => round($deducted, 2),
            'laundries' => $this->repository->activeLaundryCount($window->from, $window->to),
        ];
    }

    /**
     * Record a deduction against one laundry.
     *
     * In a transaction for consistency with every other write in this module,
     * though it is a single insert today: the reason and the amount are one fact
     * and a future release that also writes a note or a notification must not be
     * able to write half of it.
     *
     * The laundry is resolved through the model, so a tenant who somehow reached
     * this route would get a 404 on anybody else's id rather than writing to it.
     */
    public function deduct(int $laundryId, array $data, ?User $actor = null): LaundryDeduction
    {
        $laundry = Laundry::findOrFail($laundryId);

        return DB::transaction(fn () => $this->repository->createDeduction([
            'laundry_id' => $laundry->id,
            'amount' => round((float) $data['amount'], 2),
            'reason' => trim((string) $data['reason']),
            'status' => LaundryDeduction::APPLIED,
            'created_by' => $actor?->id,
        ]));
    }

    /**
     * Withdraw every deduction standing against one laundry.
     *
     * Marked `reversed`, never deleted — the same rule the rest of the money code
     * follows. A deduction that disappears takes its reason with it, and «why was
     * I charged 200 in March» stops being a question anybody can answer.
     *
     * Not limited to the window on screen. A standing claim is standing: taking
     * back only the part that happens to fall inside the dates somebody is
     * looking at would leave the rest in place without saying so.
     *
     * @return int how many were withdrawn
     */
    public function reverse(int $laundryId, ?User $actor = null): int
    {
        $laundry = Laundry::findOrFail($laundryId);

        return DB::transaction(fn () => $this->repository->appliedFor($laundry->id)->update([
            'status' => LaundryDeduction::REVERSED,
            'reversed_by' => $actor?->id,
            'reversed_at' => now(),
        ]));
    }

    /**
     * The flat shape the CSV export writes, over every laundry in the window.
     *
     * Every column the screen composes into a two-line cell gets its own column
     * here. A spreadsheet is where somebody reconciles against their own books,
     * and «مستحق ٦٩٧ · محصّل ٠» is not a figure they can sum.
     *
     * @return array{0: array<int, string>, 1: array<int, array<int, string|float|int>>}
     */
    public function exportRows(RevenueWindow $window): array
    {
        $headers = [
            'id', 'laundry', 'email', 'areas',
            'orders', 'completed', 'cancelled', 'in_progress',
            'user_paid', 'tax', 'platform_fee', 'laundry_commission',
            'laundry_entitled', 'laundry_received', 'deducted', 'net_payable',
            'reasons',
        ];

        $laundries = Laundry::query()->withCount('zones')->orderBy('id')->get();
        $ids = $laundries->pluck('id')->map(fn ($id) => (int) $id)->all();

        $totals = $this->repository->totals($window->from, $window->to, $ids);
        $deductions = $this->repository->deductions($window->from, $window->to, $ids);
        $reasons = $this->repository->reasons($window->from, $window->to, $ids);
        $standing = $this->standingCounts($ids);

        $rows = [];

        foreach ($laundries as $laundry) {
            $row = $this->row($laundry, $totals, $deductions, $reasons, $standing);

            $rows[] = [
                $row['id'], $row['name'], $row['email'], $row['areas'],
                $row['orders'], $row['completed'], $row['cancelled'], $row['in_progress'],
                $row['user_paid'], $row['tax'], $row['platform_fee'], $row['commission'],
                $row['entitled'], $row['received'], $row['deducted'], $row['net_payable'],
                // Newlines would break the cell; the separator is the one the
                // panel already uses between composed values.
                $row['reasons']->pluck('reason')->implode(' · '),
            ];
        }

        return [$headers, $rows];
    }

    /**
     * One laundry's line.
     *
     * @param  array<int, object>  $totals
     * @param  array<int, object>  $deductions
     * @param  array<int, array<int, LaundryDeduction>>  $reasons
     * @param  array<int, int>  $standing
     * @return array<string, mixed>
     */
    private function row(
        Laundry $laundry,
        array $totals,
        array $deductions,
        array $reasons,
        array $standing,
    ): array {
        $id = (int) $laundry->id;

        $total = $totals[$id] ?? null;
        $deduction = $deductions[$id] ?? null;

        $orders = (int) ($total?->getAttribute('orders_count') ?? 0);
        $completed = (int) ($total?->getAttribute('completed_count') ?? 0);
        $cancelled = (int) ($total?->getAttribute('cancelled_count') ?? 0);

        $entitled = round((float) ($total?->getAttribute('entitled_total') ?? 0), 2);
        $received = round((float) ($total?->getAttribute('settled_total') ?? 0), 2);
        $commission = round((float) ($total?->getAttribute('commission_total') ?? 0), 2);
        $platformFee = round((float) ($total?->getAttribute('platform_fee_total') ?? 0), 2);
        $basis = round((float) ($total?->getAttribute('basis_total') ?? 0), 2);
        $deducted = round((float) ($deduction?->getAttribute('deducted_total') ?? 0), 2);

        return [
            'id' => $id,
            'laundry' => $laundry,
            'name' => getLocalizedValueDashboard($laundry, 'name'),
            'email' => $laundry->email ?: '—',
            // The nearest thing Laundo has to the «branches» of the screen this
            // follows: a laundry has no branches, it has the zones it covers.
            'areas' => (int) ($laundry->zones_count ?? 0),

            'orders' => $orders,
            'completed' => $completed,
            'cancelled' => $cancelled,
            // Everything that has neither finished nor fallen over — still in
            // the laundry's hands. Derived rather than counted separately so the
            // three always add back to the total.
            'in_progress' => max($orders - $completed - $cancelled, 0),

            'user_paid' => round((float) ($total?->getAttribute('user_paid') ?? 0), 2),
            'tax' => round((float) ($total?->getAttribute('tax_total') ?? 0), 2),
            'commission' => $commission,
            'platform_fee' => $platformFee,
            // The blended rate the month came to. Derived from the result, like
            // `SettlementService::effectiveRate()`, because stacking charges have
            // no single rate to quote.
            'commission_rate' => $basis > 0 ? round($commission / $basis * 100, 2) : 0.0,

            'entitled' => $entitled,
            'received' => $received,
            'deducted' => $deducted,
            // Deliberately allowed to go negative, unlike the card. A laundry
            // deducted more than it earned this month is a real state somebody
            // has to see, and flooring it at zero on the row would hide the very
            // figure the reason column explains.
            'net_payable' => round($received - $deducted, 2),

            // Wrapped here rather than in the repository so the view keeps the
            // collection helpers it reads with, while the repository keeps a
            // return type that says what its keys are.
            'reasons' => collect($reasons[$id] ?? []),
            'standing_deductions' => $standing[$id] ?? 0,
        ];
    }

    /**
     * How many deductions stand against each laundry, at any date.
     *
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    private function standingCounts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return LaundryDeduction::query()
            ->applied()
            ->whereIn('laundry_id', $ids)
            ->groupBy('laundry_id')
            ->selectRaw('laundry_id, count(*) as total')
            ->pluck('total', 'laundry_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }
}
