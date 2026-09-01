<?php

namespace App\Modules\Order\Console;

use App\Modules\Order\Services\RecurrenceService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Asks the day's due schedules «محتاج تغسل النهاردة؟».
 *
 * Registered to run daily in bootstrap/app.php. Safe to run more than once a day
 * and safe to re-run after a failure: the prompt table's unique key makes a
 * second pass a no-op rather than a second question.
 */
class PromptRecurringOrders extends Command
{
    protected $signature = 'orders:prompt-recurring {--date= : Run as if today were this date (YYYY-MM-DD)}';

    protected $description = 'Ask customers with a due repeat schedule whether they want to wash today';

    public function handle(RecurrenceService $recurrences): int
    {
        $on = $this->option('date') ? Carbon::parse($this->option('date')) : now();

        $opened = $recurrences->promptDue($on);

        $this->info(sprintf(
            '%s: opened %d prompt(s).',
            $on->toDateString(),
            count($opened)
        ));

        foreach ($opened as $prompt) {
            $this->line("  recurrence #{$prompt->recurrence_id} → prompt #{$prompt->id}");
        }

        return self::SUCCESS;
    }
}
