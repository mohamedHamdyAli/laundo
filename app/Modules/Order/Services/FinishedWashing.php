<?php

namespace App\Modules\Order\Services;

use App\Modules\Notification\Services\OrderNotifier;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use App\Modules\User\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * «خلص الغسيل» — the laundry says the work on an order is done (the client's
 * sheet, item 18, 2026-11-08).
 *
 * Until then the collection from the laundry is neither offered, assigned nor
 * started (`OrderTask::awaitsWashing()`): a driver used to be sent the moment
 * the price was agreed and stood at the counter while the clothes were still
 * in the machine. Pressed from the order screen by the laundry — or by the
 * platform on its behalf — it moves the order `cleaning → finished`, tells the
 * customer, and offers the collection to a driver through the automatic path,
 * so with drivers assigned by hand the people who assign them are told instead.
 */
class FinishedWashing
{
    public function __construct(
        private readonly OrderStateMachine $machine,
        private readonly DriverDispatcher $dispatcher,
    ) {}

    /**
     * @throws RuntimeException `not_cleaning` when the order is anywhere but `cleaning`
     */
    public function mark(Order $order, User $by): Order
    {
        $order = DB::transaction(function () use ($order, $by) {
            // Locked, like every move of an order: two people pressing at once
            // finish it once.
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== OrderStatus::Cleaning) {
                throw new RuntimeException('not_cleaning');
            }

            return $this->machine->transition(
                $locked,
                OrderStatus::Finished,
                $by->laundry_id !== null ? 'laundry' : 'admin',
                $by,
                __('Washing finished'),
            );
        });

        // After the commit: a driver is only ever sent to an order that is
        // finished on record. A collection nobody holds is offered; one a
        // driver already holds — given to them before this rule existed — is
        // theirs, and they are told it can go now (code review, 2026-11-08).
        OrderTask::where('order_id', $order->id)
            ->where('type', TaskType::CollectFromLaundry->value)
            ->whereIn('status', [TaskStatus::Pending->value, TaskStatus::Assigned->value])
            ->get()
            ->each(function (OrderTask $task) use ($order) {
                $task->setRelation('order', $order);

                if ($task->driver_id === null) {
                    $this->dispatcher->automatically($task);

                    return;
                }

                try {
                    app(OrderNotifier::class)->collectionReady($task);
                } catch (\Throwable $e) {
                    Log::warning('[notifications] collection ready', ['task' => $task->id, 'error' => $e->getMessage()]);
                }
            });

        return $order;
    }
}
