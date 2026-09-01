<?php

namespace App\Modules\Order\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Order\Services\orderCrudService;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Orders in the dashboard.
 *
 * No create, no destroy. An order is a customer's agreement; an operator reads
 * it, and — while it is still assignable — hands it to a laundry. Everything
 * else about an order's life happens through the state machine, from the driver
 * app (P8) and the laundry's review screen (P7).
 */
class OrderController extends Controller
{
    public function __construct(private readonly orderCrudService $orderCrudService) {}

    public function index(Request $request)
    {
        $data = $this->orderCrudService->shredData();
        $view = view('admin.order.index', $data);

        return $request->ajax() ? response($view) : $view;
    }

    public function search(Request $request)
    {
        if ($request->ajax()) {
            $orders = $this->orderCrudService->search($request->get('query'));

            return response()->json([
                'table' => view('admin.order.partials._order_table_body', compact('orders'))->render(),
                'pagination' => (string) $orders->withQueryString()->links(),
            ]);
        }
    }

    public function show($id)
    {
        return view('admin.order.show', $this->orderCrudService->shredData($id));
    }

    public function assign(Request $request, $id)
    {
        $request->validate(['laundry_id' => ['required', 'exists:laundries,id']]);

        try {
            $this->orderCrudService->assign($id, (int) $request->laundry_id, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage() === 'already_in_custody'
                ? __('This order has already been collected and cannot be reassigned.')
                : __('Could not assign this order.'));
        }

        return back()->with('success', __('Order assigned successfully'));
    }
}
