<?php

namespace App\Modules\Driver\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Driver\Models\DriverBonusAward;
use App\Modules\Driver\Services\MonthlyBonusService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * «مكافآت الشهر» — what each driver earned this month, and whether it is paid.
 *
 * The screen **recomputes the month every time it is opened**, for any award
 * still `due`. A month in progress changes every day, and a figure that was
 * right on the 3rd is not a figure to approve on the 30th. An approved row is
 * never recomputed — money moved against those four numbers.
 *
 * Nothing here pays on a schedule. The list is worked out by the machine and
 * settled by a person, the same rule as refunds.
 */
class DriverBonusAwardController extends Controller
{
    public function __construct(private readonly MonthlyBonusService $bonuses) {}

    public function index(Request $request)
    {
        $month = $this->month($request);
        $period = $this->bonuses->period($month);

        // Refresh what is still open before anybody looks at it.
        $this->bonuses->computePeriod($month);

        $awards = $this->query($period, (string) $request->get('status', 'all'))->paginate(20);

        $view = view('admin.driver_bonus.index', [
            'awards' => $awards,
            'period' => $period,
            'status' => (string) $request->get('status', 'all'),
            'months' => $this->recentMonths(),
            'summary' => $this->summary($period),
        ]);

        return $request->ajax() ? response($view) : $view;
    }

    public function search(Request $request)
    {
        if (! $request->ajax()) {
            return response()->json([], 400);
        }

        $period = $this->bonuses->period($this->month($request));
        $term = (string) $request->get('query');

        $awards = $this->query($period, (string) $request->get('status', 'all'))
            ->when($term !== '', fn (Builder $q) => $q->search($term, ['driver.name', 'driver.phone']))
            ->paginate(20);

        return response()->json([
            'table' => view('admin.driver_bonus.partials._driver_bonus_table_body', compact('awards'))->render(),
            'pagination' => $awards->withQueryString()->links()->toHtml(),
        ]);
    }

    public function approve(Request $request, $id)
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        $award = DriverBonusAward::findOrFail($id);

        try {
            $this->bonuses->approve($award, $request->user(), $data['note'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('error', match ($e->getMessage()) {
                // The double click and the replayed POST, which would otherwise
                // credit a driver twice for one month.
                'award_not_due' => __('This month has already been decided.'),
                'award_is_zero' => __('There is nothing to pay for this month.'),
                default => __('Could not approve this bonus.'),
            });
        }

        return back()->with('success', __('Bonus approved and added to the wallet.'));
    }

    public function reject(Request $request, $id)
    {
        $data = $request->validate([
            // Optional, but capped: it is the answer to «ليه مخدتش المكافأة».
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $award = DriverBonusAward::findOrFail($id);

        try {
            $this->bonuses->reject($award, $request->user(), $data['note'] ?? null);
        } catch (RuntimeException) {
            return back()->with('error', __('This month has already been decided.'));
        }

        return back()->with('success', __('Bonus declined.'));
    }

    /**
     * The month being looked at, defaulting to this one.
     *
     * Parsed through the service, which refuses anything that is not a real
     * `YYYY-MM` inside a plausible range. The period comes off a query string,
     * and a URL is untrusted input — the same lesson the report range already
     * carries.
     */
    private function month(Request $request): CarbonImmutable
    {
        return $this->bonuses->parsePeriod($request->get('period'))
            ?? CarbonImmutable::now()->startOfMonth();
    }

    /**
     * @return Builder<DriverBonusAward>
     */
    private function query(string $period, string $status): Builder
    {
        return DriverBonusAward::with(['driver:id,name,phone', 'rule:id,name'])
            ->where('period', $period)
            ->when(
                in_array($status, [
                    DriverBonusAward::DUE,
                    DriverBonusAward::APPROVED,
                    DriverBonusAward::REJECTED,
                ], true),
                fn (Builder $q) => $q->where('status', $status)
            )
            // Waiting first, then the biggest. A month with forty drivers in it
            // is a screen somebody works top to bottom, and the ones already
            // decided are not the work.
            ->orderByRaw('case when status = ? then 0 else 1 end', [DriverBonusAward::DUE])
            ->orderByDesc('amount');
    }

    /**
     * @return array<string, float|int>
     */
    private function summary(string $period): array
    {
        $scope = fn () => DriverBonusAward::where('period', $period);

        return [
            'due_count' => (clone $scope())->due()->where('amount', '>', 0)->count(),
            'due_total' => (float) (clone $scope())->due()->sum('amount'),
            'approved_total' => (float) (clone $scope())->approved()->sum('amount'),
            // The number worth leading with after «what is waiting»: drivers who
            // hit a target and lost it on quality. That is a conversation to
            // have, not a figure to pay.
            'gated_count' => (clone $scope())->whereNotNull('gate_failures')->count(),
        ];
    }

    /**
     * The last twelve months, newest first, for the picker.
     *
     * A fixed, generated list rather than a free date input: the screen is
     * per-month by construction, and a range picker would invite a question it
     * cannot answer.
     *
     * @return array<int, string>
     */
    private function recentMonths(): array
    {
        $now = CarbonImmutable::now()->startOfMonth();

        return collect(range(0, 11))
            ->map(fn (int $back) => $now->subMonths($back)->format('Y-m'))
            ->all();
    }
}
