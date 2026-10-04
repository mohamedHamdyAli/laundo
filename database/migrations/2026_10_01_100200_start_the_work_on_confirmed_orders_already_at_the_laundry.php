<?php

use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Order\Services\OrderStateMachine;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Release the orders the old confirmation left at `confirmed` (2026-10-01).
 *
 * Confirming the price with the pieces already at the laundry now moves the
 * order to `cleaning`; before, nothing did, and the collection from the laundry
 * now waits for `cleaning`. An order confirmed that way before this release
 * would wait for ever. Moved through the state machine, so the step is in the
 * order's log.
 *
 * Only where the collection has not happened: an order whose legs ran ahead of
 * it (#10053 on live) is left exactly as it is, by the owner's decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        $leg = fn (TaskType $type) => OrderTask::where('type', $type->value)
            ->where('status', TaskStatus::Completed->value)->select('order_id');

        $orders = Order::withoutGlobalScopes()
            ->where('status', OrderStatus::Confirmed->value)
            ->whereIn('id', $leg(TaskType::DeliverToLaundry))
            ->whereNotIn('id', $leg(TaskType::CollectFromLaundry))
            ->get();

        foreach ($orders as $order) {
            app(OrderStateMachine::class)->transition(
                $order, OrderStatus::Cleaning, 'system', null, __('The pieces were already at the laundry.')
            );
        }

        Log::info("[orders] {$orders->count()} confirmed orders at the laundry moved to cleaning.");
    }

    public function down(): void
    {
        // Not reversed: `confirmed` with the pieces at the laundry was the bug.
    }
};
