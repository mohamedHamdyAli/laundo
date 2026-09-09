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
        $request->validate([
            'driver_id' => ['required', 'exists:users,id'],
            'rest_of_order' => ['nullable', 'boolean'],
        ]);

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

        if (! $request->boolean('rest_of_order')) {
            return back()->with('success', __('Task assigned.'));
        }

        return back()->with('success', $this->assignRest($task, $driver));
    }

    /**
     * Give the order's other open legs to the same driver.
     *
     * One driver taking the whole chain is the normal case, and it used to cost
     * four separate submissions of four separate forms. It is also what the
     * capacity rule already assumes: `max_concurrent_orders` counts distinct
     * **orders**, so the remaining legs of an order the driver is now holding
     * cost nothing further against their cap.
     *
     * Each leg still goes through `assign()` on its own, so the eligibility
     * rules are applied per leg rather than once — the delivery leg can be in a
     * different zone from the pickup, and that is exactly the case where a
     * blanket assignment would be wrong. Whatever is refused is **named** in the
     * message: silently doing three of four legs is worse than doing one, since
     * the operator would leave believing the order was covered.
     */
    private function assignRest(OrderTask $assigned, Driver $driver): string
    {
        $done = 1;
        $refused = [];

        foreach ($assigned->order->tasks as $leg) {
            if ($leg->id === $assigned->id || $leg->status->isFinished() || $leg->driver_id !== null) {
                continue;
            }

            try {
                $this->dispatcher->assign($leg, $driver);
                $done++;
            } catch (RuntimeException) {
                $refused[] = __($leg->type->label());
            }
        }

        if ($refused === []) {
            return __(':count legs assigned to :driver', ['count' => $done, 'driver' => $driver->name]);
        }

        return __(':count legs assigned to :driver. Still without a driver: :legs', [
            'count' => $done,
            'driver' => $driver->name,
            'legs' => implode(', ', $refused),
        ]);
    }

    /**
     * Try the queue again, now.
     *
     * Dispatch re-offers a queued leg on a ten-minute schedule, which is the
     * right cadence for a background sweep and the wrong one for a person who
     * has just fixed the thing that was blocking it — added a zone to a driver,
     * flipped availability, raised a cap. Without this they either wait, or
     * assign every leg by hand, having already done the work that would have
     * let dispatch do it.
     *
     * The same `dispatch()` the scheduled command calls, so a leg assigned here
     * went through the identical rules.
     */
    public function redispatch($orderId)
    {
        $order = Order::with('tasks')->findOrFail($orderId);

        $queued = $order->tasks->filter(
            fn (OrderTask $task) => $task->driver_id === null && ! $task->status->isFinished()
        );

        if ($queued->isEmpty()) {
            return back()->with('success', __('Every leg already has a driver.'));
        }

        $taken = 0;

        foreach ($queued as $task) {
            if ($this->dispatcher->dispatch($task) !== null) {
                $taken++;
            }
        }

        if ($taken === 0) {
            return back()->with('error', __('Still nobody eligible for the :count waiting legs.', [
                'count' => $queued->count(),
            ]));
        }

        return back()->with('success', __(':taken of :count waiting legs found a driver.', [
            'taken' => $taken,
            'count' => $queued->count(),
        ]));
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
