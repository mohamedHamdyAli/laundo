<?php

namespace App\Modules\Payment\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payment\Data\RevenueWindow;
use App\Modules\Payment\Requests\LaundryDeductionRequest;
use App\Modules\Payment\Services\LaundryRevenueService;
use App\Modules\User\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * «إيرادات المغاسل» — one row per laundry, and what it came to.
 *
 * Reading is gated on `laundry_revenue.view`. The two writes are **not** gated
 * on `laundry_revenue.update`: they take money off a laundry, so they sit behind
 * `setting.update` alongside the commission terms. That is the boundary
 * `MoneyBoundaryTest` guards — a laundry owner holds `laundry.update` by design,
 * so anything that decides what they are paid has to hang off a permission they
 * do not hold. The routes enforce it; nothing here repeats the check, so the two
 * cannot drift.
 *
 * Nothing in this class strips a global scope. `Order`, `OrderSettlement` and
 * `LaundryDeduction` are all tenant-scoped and `Laundry` scopes itself on `id`,
 * which for a super admin is a no-op — that null from `LaundryContext` is the
 * whole bypass — and for anybody confined to a laundry narrows the screen to
 * their own row instead of leaking.
 */
class LaundryRevenueController extends Controller
{
    public function __construct(private readonly LaundryRevenueService $revenue) {}

    public function index(Request $request)
    {
        $view = view('admin.laundry_revenue.index', $this->revenue->shredData($request));

        return $request->ajax() ? response($view) : $view;
    }

    public function search(Request $request)
    {
        // A bare GET is an empty 200 on every other module's search action; this
        // one says so with a 400 rather than rendering half a page.
        if (! $request->ajax()) {
            return response()->json([], 400);
        }

        $data = $this->revenue->shredData($request);

        return response()->json([
            'table' => view('admin.laundry_revenue.partials._laundry_revenue_table_body', [
                'rows' => $data['rows'],
            ])->render(),
            'pagination' => $data['rows']->withQueryString()->links()->toHtml(),
        ]);
    }

    public function deduct(LaundryDeductionRequest $request, int $laundry): RedirectResponse
    {
        $actor = $request->user();

        $this->revenue->deduct($laundry, $request->validated(), $actor instanceof User ? $actor : null);

        return back()->with('success', __('The deduction was recorded.'));
    }

    public function reverse(Request $request, int $laundry): RedirectResponse
    {
        $actor = $request->user();

        $reversed = $this->revenue->reverse($laundry, $actor instanceof User ? $actor : null);

        // Says how many, because the button withdraws every standing deduction
        // rather than the one the operator was looking at. «تم التراجع» alone
        // would leave them guessing whether the other two went too.
        return back()->with(
            'success',
            $reversed > 0
                ? trans_choice(':count deduction was withdrawn.|:count deductions were withdrawn.', $reversed, ['count' => $reversed])
                : __('There was nothing to withdraw.')
        );
    }

    /**
     * The same figures, flattened, streamed.
     *
     * Streamed rather than assembled in memory for the reason the reports give:
     * the file gets big on exactly the day the business finally has enough data
     * to want it.
     */
    public function export(Request $request): StreamedResponse
    {
        $window = RevenueWindow::fromRequest($request);

        [$headers, $rows] = $this->revenue->exportRows($window);

        $filename = 'laundo-laundry-revenue-'
            .$window->from->toDateString().'-to-'.$window->to->toDateString().'.csv';

        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');

            // A BOM, so Excel opens Arabic as Arabic rather than as mojibake —
            // which is how most of these files will actually be read.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, $headers);

            foreach ($rows as $row) {
                fputcsv($out, $row);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
