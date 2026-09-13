<?php

namespace App\Modules\Payment\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payment\Models\OrderSettlement;
use App\Support\LaundryContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * «تسويات الطلبات» — how each order was divided.
 *
 * The one screen in the Money group that **two different audiences read**, and
 * they read it for opposite reasons: the super admin opens it to see what the
 * platform earned, a laundry owner to see what it is owed. The model is
 * tenant-scoped, so both get the same screen and neither sees the other's rows —
 * which is why this is not a second view of `admin.earning`, whose model is
 * deliberately unscoped because a laundry is not party to a driver's pay.
 *
 * The headline figure is **what is still pending**, for the same reason the
 * earnings screen leads with what is owed rather than what has been paid: the
 * settled total is history, and the pending total is the money that has not
 * arrived yet.
 */
class SettlementController extends Controller
{
    public function index(Request $request)
    {
        $status = (string) $request->get('status', 'all');

        $view = view('admin.settlement.index', [
            'settlements' => $this->query($status)->paginate(20),
            'status' => $status,
            'summary' => $this->summary(),
            'isTenant' => LaundryContext::isTenant(),
        ]);

        return $request->ajax() ? response($view) : $view;
    }

    public function search(Request $request)
    {
        if (! $request->ajax()) {
            return response()->json([], 400);
        }

        $term = (string) $request->get('query');

        $settlements = $this->query((string) $request->get('status', 'all'))
            ->when($term !== '', fn (Builder $q) => $q->search($term, [
                'order.code',
                'laundry.name',
            ]))
            ->paginate(20);

        return response()->json([
            'table' => view('admin.settlement.partials._settlement_table_body', [
                'settlements' => $settlements,
                'isTenant' => LaundryContext::isTenant(),
            ])->render(),
            'pagination' => $settlements->withQueryString()->links()->toHtml(),
        ]);
    }

    /**
     * @return Builder<OrderSettlement>
     */
    private function query(string $status): Builder
    {
        return OrderSettlement::with(['order:id,code,status', 'laundry:id,name', 'lines'])
            ->when(
                in_array($status, [
                    OrderSettlement::PENDING,
                    OrderSettlement::SETTLED,
                    OrderSettlement::CANCELLED,
                ], true),
                fn (Builder $q) => $q->where('status', $status)
            )
            // Pending first, then newest. A settlement waiting on a payee that
            // does not exist is the only kind anybody has to act on, and «newest
            // first» buries it under a month of settled rows.
            ->orderByRaw('case when status = ? then 0 else 1 end', [OrderSettlement::PENDING])
            ->latest('id');
    }

    /**
     * @return array<string, float|int>
     */
    private function summary(): array
    {
        // Every figure runs through the same tenant-scoped model, so a laundry
        // owner's totals are its own — the cards cannot leak what the list does
        // not show.
        $month = fn () => OrderSettlement::settled()
            ->whereBetween('settled_at', [now()->startOfMonth(), now()->endOfDay()]);

        return [
            'pending_count' => OrderSettlement::pending()->count(),
            'pending_laundry' => (float) OrderSettlement::pending()->sum('laundry_amount'),
            'commission_month' => (float) $month()->sum('commission_amount'),
            'laundry_month' => (float) $month()->sum('laundry_amount'),
            'commission_total' => (float) OrderSettlement::settled()->sum('commission_amount'),
        ];
    }
}
