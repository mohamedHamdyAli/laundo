<?php

namespace App\Modules\Order\Console;

use App\Modules\Order\Services\DriverDispatcher;
use Illuminate\Console\Command;

/**
 * Sweeps the dispatch queue.
 *
 * A task queues when nobody was eligible at the moment it was created. The events
 * that make one dispatchable later — a driver coming on shift, flipping
 * «متاح لاستقبال المهام», or finishing a job and dropping under their cap — none
 * of them knows the queue exists. So something has to come back and look.
 *
 * Every ten minutes rather than every minute: a task waiting eleven minutes for a
 * driver who was not there is not the problem worth solving with a tighter loop.
 */
class DispatchQueuedTasks extends Command
{
    protected $signature = 'tasks:dispatch';

    protected $description = 'Offer queued driver tasks to any driver who has since become eligible';

    public function handle(DriverDispatcher $dispatcher): int
    {
        $assigned = $dispatcher->sweep();

        $this->info("Dispatched {$assigned} task(s).");

        return self::SUCCESS;
    }
}
