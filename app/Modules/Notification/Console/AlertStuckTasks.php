<?php

namespace App\Modules\Notification\Console;

use App\Modules\Notification\Data\NotificationMessage;
use App\Modules\Notification\Enums\NotificationEvent;
use App\Modules\Notification\Models\NotificationLog;
use App\Modules\Notification\Services\NotificationDispatcher;
use App\Modules\Order\Models\OrderTask;
use App\Modules\User\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Tells operations about work nobody has taken.
 *
 * The dispatch queue has had a counter on the orders page since P8, and a counter
 * nobody is prompted to look at is a counter nobody looks at. A task idle for two
 * hours is a customer waiting while the business does not know it.
 *
 * Sends **one alert per task**, ever. A queue that re-announces itself every hour
 * teaches operations to ignore the alert, which is worse than not sending one —
 * `NotificationLog` is the memory that makes that guarantee, since it already
 * records the subject of every message.
 */
class AlertStuckTasks extends Command
{
    protected $signature = 'tasks:alert-stuck {--hours=2 : How long a task may sit unassigned before it is raised}';

    protected $description = 'Notify operations about tasks that have waited too long for a driver';

    public function handle(NotificationDispatcher $dispatcher): int
    {
        $hours = max((int) $this->option('hours'), 1);
        $cutoff = now()->subHours($hours);

        $stuck = OrderTask::queued()
            ->with('order:id,code')
            ->where('created_at', '<=', $cutoff)
            ->get();

        if ($stuck->isEmpty()) {
            $this->info('No task has been waiting longer than '.$hours.'h.');

            return self::SUCCESS;
        }

        $operators = $this->operators();

        if ($operators->isEmpty()) {
            $this->warn('No super admin to notify.');

            return self::SUCCESS;
        }

        $raised = 0;

        foreach ($stuck as $task) {
            if ($this->alreadyRaised($task)) {
                continue;
            }

            $dispatcher->sendMany($operators, new NotificationMessage(
                event: NotificationEvent::TaskQueuedTooLong,
                title: __('A task is waiting for a driver'),
                body: __('Order :code has had no driver for over :hours hours.', [
                    'code' => $task->order?->code,
                    'hours' => $hours,
                ]),
                url: '/admin/order/show/'.$task->order_id,
                data: ['task_id' => (string) $task->id],
                subject: $task,
            ));

            $raised++;
        }

        $this->info("Raised {$raised} of {$stuck->count()} waiting task(s).");

        return self::SUCCESS;
    }

    /**
     * Whether this exact task has already been raised.
     *
     * The log is the memory. Without it the same twelve tasks are announced every
     * hour until somebody mutes the whole category.
     */
    private function alreadyRaised(OrderTask $task): bool
    {
        return NotificationLog::where('event', NotificationEvent::TaskQueuedTooLong->value)
            ->where('subject_type', OrderTask::class)
            ->where('subject_id', $task->id)
            ->exists();
    }

    /**
     * @return Collection<int, User>
     */
    private function operators()
    {
        return User::whereHas('role', fn ($q) => $q->where('slug', 'super_admin'))->get();
    }
}
