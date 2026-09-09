<?php

namespace App\Modules\Order\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Order\Services\dispatchBoardService;
use App\Modules\Order\Services\DriverDispatcher;
use Illuminate\Http\Request;

/**
 * Every journey waiting for a person, in one place.
 *
 * Assigning goes through the existing `admin.order.tasks.assign`, which
 * redirects `back()` — so the board is where the operator lands again, and
 * there is one code path for giving a leg to a driver rather than two that can
 * disagree about the rules.
 */
class DispatchController extends Controller
{
    public function __construct(
        private readonly dispatchBoardService $board,
        private readonly DriverDispatcher $dispatcher,
    ) {}

    public function index(Request $request)
    {
        $data = $this->board->shredData($request->get('query'), $request->get('leg'));
        $view = view('admin.dispatch.index', $data);

        return $request->ajax() ? response($view) : $view;
    }

    public function search(Request $request)
    {
        if (! $request->ajax()) {
            return null;
        }

        $data = $this->board->shredData($request->get('query'), $request->get('leg'));

        return response()->json([
            'table' => view('admin.dispatch.partials._dispatch_table_body', $data)->render(),
            'pagination' => (string) $data['legs']->withQueryString()->links(),
        ]);
    }

    /**
     * Try every waiting leg on the board again.
     *
     * The same `dispatch()` the scheduled sweep calls. An operator who has just
     * given a driver a zone or raised a cap should not have to wait out the
     * ten-minute cron to see whether it took, and should not have to open each
     * affected order to do by hand what dispatch would now do for them.
     */
    public function redispatchAll()
    {
        $waiting = OrderTask::query()
            ->whereIn('order_id', Order::query()->select('id'))
            ->whereNull('driver_id')
            ->where('status', 'pending')
            ->get();

        if ($waiting->isEmpty()) {
            return back()->with('success', __('Nothing is waiting for a driver.'));
        }

        $taken = 0;

        foreach ($waiting as $task) {
            if ($this->dispatcher->dispatch($task) !== null) {
                $taken++;
            }
        }

        if ($taken === 0) {
            return back()->with('error', __('Still nobody eligible for the :count waiting legs.', [
                'count' => $waiting->count(),
            ]));
        }

        return back()->with('success', __(':taken of :count waiting legs found a driver.', [
            'taken' => $taken,
            'count' => $waiting->count(),
        ]));
    }
}
