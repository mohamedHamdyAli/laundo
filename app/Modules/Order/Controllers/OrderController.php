<?php

namespace App\Modules\Order\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Order\Services\orderCrudService;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Orders in the dashboard.
 *
 * No create. An order is a customer's agreement; an operator reads it, and —
 * while it is still assignable — hands it to a laundry. Everything else about an
 * order's life happens through the state machine, from the driver app (P8) and
 * the laundry's review screen (P7).
 *
 * destroy() is the one exception, and a narrow one: `OrderDeletionGuard` allows
 * it only for an order nobody has collected and no money has touched.
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
            return back()->with('error', match ($e->getMessage()) {
                'already_in_custody' => __('This order has already been collected and cannot be reassigned.'),
                // Said plainly rather than as a generic failure: it is not a
                // mistake the operator made, it is work that is not theirs to
                // move.
                'not_yours_to_route' => __('Which laundry handles an order is decided by the platform.'),
                default => __('Could not assign this order.'),
            });
        }

        return back()->with('success', __('Order assigned successfully'));
    }

    /**
     * Erases an order the guard agrees is erasable.
     *
     * A refusal comes back as the guard's own sentence rather than a generic
     * failure. The rules are not obvious from the screen — «this one has a
     * settlement» is not something an operator can see in the list — so the
     * message has to carry the reason or the button looks broken.
     *
     * Redirects to the list, not `back()`: the row the operator was looking at
     * no longer exists.
     */
    public function destroy($id)
    {
        try {
            $this->orderCrudService->deleteRecord($id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.order.index')->with('success', __('Deleted Successfully'));
    }

    /**
     * Erases a selection, and says what it would not erase and why.
     *
     * The whole point of the checkbox column is clearing a list in one go, so a
     * selection that contains one undeletable order must not fail as a unit —
     * the rest go, and the refusals come back named. Anything else sends the
     * operator back to deleting rows one at a time.
     *
     * `back()`, not the index route: the operator may have been filtering or
     * searching, and throwing them to an unfiltered page one is its own small
     * punishment for using the feature.
     */
    public function bulkDestroy(Request $request)
    {
        $data = $request->validate([
            // A cap, not a formality. Without it one request can be made to walk
            // the entire table, and every row costs the guard five queries.
            'ids' => ['required', 'array', 'max:100'],
            'ids.*' => ['required', 'integer', 'exists:orders,id'],
        ]);

        $result = $this->orderCrudService->deleteMany($data['ids']);

        if ($result['deleted'] > 0) {
            $request->session()->flash('success', trans_choice(
                '{1}:count order deleted|[2,*]:count orders deleted',
                $result['deleted'],
                ['count' => $result['deleted']]
            ));
        }

        if ($result['refused'] !== []) {
            // Named one per line rather than «3 could not be deleted». The
            // reasons differ between rows, and a count gives the operator
            // nothing to act on.
            $request->session()->flash('error', __('Kept:').' '.collect($result['refused'])
                ->map(fn (string $why, string $code) => "$code — $why")
                ->implode(' | '));
        }

        return back();
    }
}
