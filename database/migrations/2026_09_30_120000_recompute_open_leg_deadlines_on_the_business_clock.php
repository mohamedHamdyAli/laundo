<?php

use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Order\Services\TaskGenerator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Every open leg's deadline, again, on the business's clock.
 *
 * Until 2026-09-30 a window's «08:00–10:00» was read as UTC while it means
 * Cairo, so every `due_at` sat three hours after the moment it stands for, and
 * «late» fired three hours late — or at once, on an order booked into a window
 * that had already ended. `TaskGenerator` now builds them through `SlotClock`.
 *
 * Only legs still to be done: a completed leg's deadline is history, and the
 * on-time figures already measured against it. Through the models, so each
 * change is in the activity log; a leg whose order has no date for it yet (a
 * postponed one) is left alone, as the generator would leave it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $generator = app(TaskGenerator::class);
        $moved = 0;

        OrderTask::query()
            ->whereIn('status', [TaskStatus::Pending->value, TaskStatus::Assigned->value, TaskStatus::Started->value])
            ->with(['order.pickupSlot', 'order.deliverySlot'])
            ->chunkById(200, function ($tasks) use ($generator, &$moved) {
                foreach ($tasks as $task) {
                    if (! $task->order) {
                        continue;
                    }

                    $due = $generator->dueFor($task->order, $task->type);

                    if ($due === null || ($task->due_at && $task->due_at->equalTo($due))) {
                        continue;
                    }

                    $task->forceFill(['due_at' => $due])->save();
                    $moved++;
                }
            });

        Log::info("[deadlines] {$moved} open legs re-dated on ".displayTimezone().'.');
    }

    public function down(): void
    {
        // Not reversed: the old values were the bug.
    }
};
