<?php

namespace App\Modules\Order\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Order\Services\OrderTodayService;
use Illuminate\Http\Request;

/**
 * «طلبات اليوم» — HTTP only; the counting is the service's.
 *
 * Gated on `order.view`, the same as the orders list: it is those orders, read
 * as a day's work. The Order model's tenant scope confines a laundry's own
 * people to their own, so the super admin and a laundry owner reach the same
 * screen and neither sees the other's rows.
 */
class OrderTodayController extends Controller
{
    public function __construct(private readonly OrderTodayService $today) {}

    public function index(Request $request)
    {
        $view = view('admin.order_today.index', $this->today->shredData($request->query()));

        return $request->ajax() ? response($view) : $view;
    }

    /**
     * The rows and the totals together, for the filters and the search box. The
     * totals are summed over exactly the rows shown, so they are redrawn with
     * them — a card still counting yesterday's filter would contradict the list
     * under it.
     */
    public function search(Request $request)
    {
        if (! $request->ajax()) {
            return response()->json([], 400);
        }

        $data = $this->today->shredData($request->query());

        return response()->json([
            'table' => view('admin.order_today.partials._order_today_table_body', $data)->render(),
            'summary' => view('admin.order_today.partials._order_today_summary', $data)->render(),
            'pagination' => '',
        ]);
    }
}
