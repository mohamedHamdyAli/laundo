<?php

namespace App\Modules\Report\Services;

use App\Modules\Notification\Models\NotificationLog;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderPriceQuery;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Payment\Models\Refund;
use App\Modules\Wallet\Models\Wallet;

/**
 * The things that are quietly wrong right now.
 *
 * Every figure here is a follow-up raised in an earlier phase that has had no home
 * since. **Orders stuck at `reviewed`** is the silence problem flagged in P7 — the
 * decision was that an unanswered order waits indefinitely, which is defensible
 * only if somebody can see the ones that are waiting. **Wallets out of balance**
 * is the drift that is invisible until a customer disputes a figure. **Failed
 * notifications** is how a device token Firebase invalidated months ago stops
 * being invisible.
 *
 * Deliberately not date-ranged: these are not history, they are the state of the
 * business at this moment, and a stuck order from last month is more urgent than
 * one from this morning, not less.
 */
class OperationsReport
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'orders_awaiting_customer' => $this->awaitingCustomer(),
            'orders_unassigned' => $this->unassigned(),
            'tasks_queued' => $this->queuedTasks(),
            'tasks_exhausted' => $this->exhaustedTasks(),
            'refunds_pending' => Refund::pending()->count(),
            'refunds_unsettled' => Refund::where('status', Refund::APPROVED)->whereNull('settled_at')->count(),
            'price_questions_open' => $this->openQuestions(),
            'notifications_failed' => NotificationLog::failures()->count(),
            'wallets_out_of_balance' => $this->unreconciledWallets(),
        ];
    }

    /**
     * Orders waiting on a customer who has not answered.
     *
     * The P7 decision was that these wait indefinitely rather than auto-approving.
     * That is only defensible while somebody can see them.
     *
     * @return array<int, array<string, mixed>>
     */
    public function awaitingCustomer(): array
    {
        $orders = Order::where('status', OrderStatus::Reviewed->value)
            ->with('customer:id,name,phone')
            ->orderBy('reviewed_at')
            ->limit(50)
            ->get();

        $out = [];

        foreach ($orders as $order) {
            $out[] = [
                'id' => $order->id,
                'code' => $order->code,
                'customer' => $order->customer?->name,
                'phone' => $order->customer?->phone,
                'total' => $order->payableTotal(),
                'waiting_since' => $order->reviewed_at ? humanDate($order->reviewed_at) : null,
                'waiting_hours' => $order->reviewed_at
                    ? (int) $order->reviewed_at->diffInHours(now())
                    : null,
            ];
        }

        return $out;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function unassigned(): array
    {
        $orders = Order::unassigned()
            ->active()
            ->with('customer:id,name')
            ->orderBy('created_at')
            ->limit(50)
            ->get();

        $out = [];

        foreach ($orders as $order) {
            $out[] = [
                'id' => $order->id,
                'code' => $order->code,
                'customer' => $order->customer?->name,
                'placed' => humanDate($order->created_at),
                'waiting_hours' => (int) $order->created_at->diffInHours(now()),
            ];
        }

        return $out;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function queuedTasks(): array
    {
        $tasks = OrderTask::queued()
            ->with('order:id,code')
            ->orderBy('created_at')
            ->limit(50)
            ->get();

        $out = [];

        foreach ($tasks as $task) {
            $out[] = [
                'id' => $task->id,
                'order_id' => $task->order_id,
                'order_code' => $task->order?->code,
                'leg' => __($task->type->label()),
                'waiting_hours' => (int) $task->created_at->diffInHours(now()),
                'attempts' => $task->attempts,
            ];
        }

        return $out;
    }

    /**
     * Tasks that failed their way out of the pool and need a person.
     *
     * @return array<int, array<string, mixed>>
     */
    public function exhaustedTasks(): array
    {
        $tasks = OrderTask::where('status', 'failed')
            ->where('attempts', '>=', OrderTask::MAX_ATTEMPTS)
            ->with('order:id,code')
            ->latest('updated_at')
            ->limit(50)
            ->get();

        $out = [];

        foreach ($tasks as $task) {
            $out[] = [
                'id' => $task->id,
                'order_id' => $task->order_id,
                'order_code' => $task->order?->code,
                'leg' => __($task->type->label()),
                'attempts' => $task->attempts,
                'reason' => $task->failure_reason ? __($task->failure_reason->label()) : null,
            ];
        }

        return $out;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function openQuestions(): array
    {
        $queries = OrderPriceQuery::open()
            ->whereIn('order_id', Order::query()->select('id'))
            ->with(['order:id,code', 'customer:id,name'])
            ->orderBy('created_at')
            ->limit(50)
            ->get();

        $out = [];

        foreach ($queries as $query) {
            $out[] = [
                'id' => $query->id,
                'order_id' => $query->order_id,
                'order_code' => $query->order?->code,
                'customer' => $query->customer?->name,
                'message' => $query->message,
                'waiting_hours' => (int) $query->created_at->diffInHours(now()),
            ];
        }

        return $out;
    }

    /**
     * Wallets whose cached balance no longer equals their ledger.
     *
     * The check has existed since P9b and nothing has ever run it across every
     * wallet. A drift is invisible until somebody disputes a figure, and by then
     * the history that would explain it is weeks old.
     *
     * @return array<int, array<string, mixed>>
     */
    public function unreconciledWallets(): array
    {
        $out = [];

        foreach (Wallet::with('owner:id,name,phone')->get() as $wallet) {
            if ($wallet->isReconciled()) {
                continue;
            }

            $out[] = [
                'id' => $wallet->id,
                'owner' => $wallet->owner?->name,
                'phone' => $wallet->owner?->phone,
                'cached' => (float) $wallet->balance,
                'ledger' => $wallet->ledgerBalance(),
                'difference' => round((float) $wallet->balance - $wallet->ledgerBalance(), 2),
            ];
        }

        return $out;
    }
}
