<?php

namespace App\Console\Commands;

use App\Modules\Notification\Models\DeviceToken;
use App\Modules\Notification\Models\NotificationLog;
use App\Modules\Payment\Models\Payment;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Retention.
 *
 * Three tables grew without any bound at all, each for a good reason at the time:
 *
 *   - `notification_logs` records every delivery attempt on every channel,
 *     including the deliberate skips. That is what makes "I never got it"
 *     answerable, and it is one row per channel per notification.
 *   - `payments.payload` keeps the provider's response verbatim. Invaluable in a
 *     dispute, and it is data we did not write and cannot summarise safely.
 *   - `device_tokens` are only ever removed when Firebase permanently rejects one.
 *     A handset somebody stopped using two years ago stays on the list for ever.
 *
 * None of them is a problem today. All three become one at a volume this business
 * intends to reach, and the moment to decide retention is before the table is
 * large enough that deleting from it is itself an operation.
 *
 * Three rules this command follows:
 *
 *   1. **Nothing that could still be needed is touched.** A failed notification is
 *      kept longer than a successful one, because a failure is what somebody
 *      investigates. A payment's payload is cleared, never its row.
 *   2. **Chunked deletes.** A single `delete()` over a year of logs locks the
 *      table for as long as it takes; a hundred thousand rows at once is how a
 *      retention job becomes an outage.
 *   3. **`--dry-run` first.** It reports exactly what it would remove and touches
 *      nothing, so the first run in production can be read before it is trusted.
 */
class PruneOldRecords extends Command
{
    protected $signature = 'laundo:prune
        {--dry-run : Report what would be removed and change nothing}
        {--logs=90 : Keep delivered notification logs for this many days}
        {--failed-logs=365 : Keep FAILED notification logs for this many days}
        {--payloads=180 : Clear payment provider payloads older than this many days}
        {--tokens=180 : Remove device tokens unused for this many days}';

    protected $description = 'Apply retention to notification logs, payment payloads and device tokens';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Dry run — nothing will be changed.');
        }

        $removed = 0;
        $removed += $this->pruneNotificationLogs($dryRun);
        $removed += $this->prunePaymentPayloads($dryRun);
        $removed += $this->pruneDeviceTokens($dryRun);

        $this->newLine();
        $this->info($dryRun
            ? "Would affect {$removed} row(s)."
            : "Affected {$removed} row(s).");

        return self::SUCCESS;
    }

    /**
     * Delivered logs go sooner than failed ones.
     *
     * A successful send is a receipt nobody reads after a quarter. A failure is
     * the thing somebody investigates months later when a customer says they
     * never heard from us, so it is kept four times as long.
     */
    private function pruneNotificationLogs(bool $dryRun): int
    {
        $keepDays = (int) $this->option('logs');
        $keepFailedDays = (int) $this->option('failed-logs');

        $cutoff = now()->subDays($keepDays);
        $failedCutoff = now()->subDays($keepFailedDays);

        $settled = NotificationLog::where('created_at', '<', $cutoff)
            ->where('status', '!=', NotificationLog::FAILED);

        $failed = NotificationLog::where('created_at', '<', $failedCutoff)
            ->where('status', NotificationLog::FAILED);

        $settledCount = (clone $settled)->count();
        $failedCount = (clone $failed)->count();

        $this->line("notification_logs — sent/skipped older than {$keepDays}d: {$settledCount}");
        $this->line("notification_logs — failed older than {$keepFailedDays}d: {$failedCount}");

        if ($dryRun) {
            return $settledCount + $failedCount;
        }

        return $this->chunkDelete($settled) + $this->chunkDelete($failed);
    }

    /**
     * The payload is cleared; the payment row stays.
     *
     * The row is the money — what was charged, when, against which order. That is
     * accounting and it is never deleted. The payload is the provider's own JSON
     * around it, useful for a dispute and worth nothing after one.
     */
    private function prunePaymentPayloads(bool $dryRun): int
    {
        $keepDays = (int) $this->option('payloads');
        $cutoff = now()->subDays($keepDays);

        $query = Payment::where('created_at', '<', $cutoff)->whereNotNull('payload');

        $count = (clone $query)->count();

        $this->line("payments — payloads older than {$keepDays}d to clear: {$count}");

        if ($dryRun || $count === 0) {
            return $count;
        }

        $cleared = 0;

        // In chunks, and by id rather than by offset: clearing the payload changes
        // what the `whereNotNull` matches, so a paginated walk would skip rows.
        $query->select('id')->chunkById(500, function ($payments) use (&$cleared) {
            $cleared += Payment::whereIn('id', $payments->pluck('id'))->update(['payload' => null]);
        });

        return $cleared;
    }

    /**
     * A token nobody has used in months is a handset nobody is holding.
     *
     * Firebase only tells us about a token when we try to send to it, so a device
     * that stopped being used is never rejected — it simply goes quiet. `last_used_at`
     * is the only signal, and a token that has never been used falls back to when
     * it was registered.
     */
    private function pruneDeviceTokens(bool $dryRun): int
    {
        $keepDays = (int) $this->option('tokens');
        $cutoff = now()->subDays($keepDays);

        $query = DeviceToken::where(function ($q) use ($cutoff) {
            $q->where('last_used_at', '<', $cutoff)
                ->orWhere(function ($inner) use ($cutoff) {
                    $inner->whereNull('last_used_at')->where('created_at', '<', $cutoff);
                });
        });

        $count = (clone $query)->count();

        $this->line("device_tokens — unused for {$keepDays}d: {$count}");

        if ($dryRun) {
            return $count;
        }

        return $this->chunkDelete($query);
    }

    /**
     * Delete in chunks so the table is never locked for long.
     *
     * A single delete over a year of logs holds the table for as long as it takes,
     * which is how a retention job becomes an outage on the night it first matters.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private function chunkDelete($query, int $size = 1000): int
    {
        $total = 0;

        do {
            $deleted = (clone $query)->limit($size)->delete();
            $total += $deleted;
        } while ($deleted > 0);

        return $total;
    }
}
