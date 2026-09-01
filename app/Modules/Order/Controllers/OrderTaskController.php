<?php

namespace App\Modules\Order\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Driver\Models\Driver;
use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Order\Services\DriverDispatcher;
use App\Modules\Order\Services\TaskGenerator;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Operations' side of dispatch.
 *
 * Reassigning and releasing only — a task is created by the system when an order
 * is confirmed, and completing one is the driver's act, done in the field with a
 * scan and a signature. Letting an operator tick a task off from a desk would
 * quietly destroy the only proof the handover happened.
 *
 * The order is fetched through the tenant-scoped model, so a laundry can only
 * ever reach its own tasks.
 */
class OrderTaskController extends Controller
{
    public function __construct(
        private readonly DriverDispatcher $dispatcher,
        private readonly TaskGenerator $generator,
    ) {}

    public function assign(Request $request, $id)
    {
        $request->validate(['driver_id' => ['required', 'exists:users,id']]);

        $task = $this->find($id);
        $driver = Driver::find($request->get('driver_id'));

        if (! $driver) {
            return back()->with('error', __('Driver not found.'));
        }

        try {
            $this->dispatcher->assign($task, $driver);
        } catch (RuntimeException $e) {
            return back()->with('error', match ($e->getMessage()) {
                'driver_not_eligible' => __('This driver does not serve the area, is unavailable, or is at capacity.'),
                'task_completed' => __('This leg is already completed and cannot be reassigned.'),
                default => __('Could not assign the task.'),
            });
        }

        return back()->with('success', __('Task assigned.'));
    }

    /**
     * Take a task off a driver and put it back in the queue.
     */
    public function release($id)
    {
        $task = $this->find($id);

        if ($task->status === TaskStatus::Completed) {
            return back()->with('error', __('This leg is already completed.'));
        }

        $this->dispatcher->release($task);

        return back()->with('success', __('Task returned to the dispatch queue.'));
    }

    /**
     * Build the chain for an order confirmed before P8 existed.
     */
    public function generate($orderId)
    {
        $order = Order::findOrFail($orderId);

        $this->generator->generate($order);

        return back()->with('success', __('Tasks created.'));
    }

    private function find($id): OrderTask
    {
        // Rooted in the tenant-scoped Order, so an id belonging to another
        // laundry's order is simply not found.
        return OrderTask::whereIn('order_id', Order::query()->select('id'))
            ->with('order')
            ->findOrFail($id);
    }
}
