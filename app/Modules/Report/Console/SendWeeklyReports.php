<?php

namespace App\Modules\Report\Console;

use App\Mail\WeeklyReportMail;
use App\Models\Role;
use App\Modules\Laundry\Models\Laundry;
use App\Modules\Report\Services\WeeklyDigest;
use App\Modules\User\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * «ملخص أسبوعي للسوبر أدمن + لكل مغسلة تقريرها» — the owner's decision.
 *
 * The four reports have always been on the dashboard and have always exported.
 * What was missing is that somebody has to remember to open them, and the laundry
 * owner who never logs in is exactly the one whose numbers are falling.
 *
 * Weekly rather than daily: a day is noise at this volume, and a digest people
 * stop opening is worse than no digest, because it is then also the thing they do
 * not open on the week it mattered.
 */
class SendWeeklyReports extends Command
{
    protected $signature = 'laundo:weekly-reports
        {--dry-run : List the recipients and figures without sending anything}
        {--only= : Restrict to "platform" or "laundries"}';

    protected $description = 'Email last week\'s figures to the super admins and to each laundry';

    public function handle(WeeklyDigest $digests): int
    {
        $range = WeeklyDigest::lastWeek();
        $only = $this->option('only');
        $dry = (bool) $this->option('dry-run');

        $this->info($range->label().($dry ? ' — dry run' : ''));

        $sent = 0;
        $skipped = 0;

        if ($only !== 'laundries') {
            [$s, $k] = $this->platform($digests, $range, $dry);
            $sent += $s;
            $skipped += $k;
        }

        if ($only !== 'platform') {
            [$s, $k] = $this->laundries($digests, $range, $dry);
            $sent += $s;
            $skipped += $k;
        }

        $this->info("sent: {$sent}   skipped: {$skipped}");

        return self::SUCCESS;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function platform(WeeklyDigest $digests, $range, bool $dry): array
    {
        $recipients = User::whereHas('role', fn ($q) => $q->where('slug', Role::SUPER_ADMIN))
            ->where('status', 'active')
            ->whereNotNull('email')
            ->get();

        if ($recipients->isEmpty()) {
            $this->warn('no platform recipient has an email address');

            return [0, 1];
        }

        // Built once and sent to everybody. The figures are identical, and
        // rebuilding them per recipient is a dozen report queries for nothing.
        $digest = $digests->platform($range);

        return $this->deliver($recipients, $digest, $digests, $dry);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function laundries(WeeklyDigest $digests, $range, bool $dry): array
    {
        $sent = 0;
        $skipped = 0;

        $laundries = Laundry::withoutGlobalScopes()
            ->where('status', 'active')
            ->orderBy('id')
            ->get();

        foreach ($laundries as $laundry) {
            $owners = User::where('laundry_id', $laundry->id)
                ->whereHas('role', fn ($q) => $q->where('slug', Role::LAUNDRY_OWNER))
                ->where('status', 'active')
                ->whereNotNull('email')
                ->get();

            if ($owners->isEmpty()) {
                // Not an error. A laundry run from a phone with no email address
                // is a normal state, and the report is on the dashboard either way.
                $this->line('  skip laundry '.$laundry->id.' — no owner with an email');
                $skipped++;

                continue;
            }

            // Scoped by acting as the first owner; every owner of the same
            // laundry sees the same figures.
            $digest = $digests->forLaundry($laundry, $owners->first(), $range);

            [$s, $k] = $this->deliver($owners, $digest, $digests, $dry);
            $sent += $s;
            $skipped += $k;
        }

        return [$sent, $skipped];
    }

    /**
     * @param  Collection<int, User>  $recipients
     * @param  array<string, mixed>  $digest
     * @return array{0: int, 1: int}
     */
    private function deliver($recipients, array $digest, WeeklyDigest $digests, bool $dry): array
    {
        $sent = 0;
        $skipped = 0;
        $csv = $digests->csv($digest);

        foreach ($recipients as $recipient) {
            if ($dry) {
                $this->line('  would send to '.$recipient->email.' — '.$digest['title']);
                $sent++;

                continue;
            }

            try {
                Mail::to($recipient->email)->send(new WeeklyReportMail($digest, $csv));
                $sent++;
            } catch (Throwable $e) {
                // One bad address must not cost everybody else their report. The
                // failure is logged rather than thrown, because a scheduled
                // command that dies halfway leaves no record of who did get one.
                Log::warning('weekly report failed', [
                    'email' => $recipient->email,
                    'error' => $e->getMessage(),
                ]);
                $this->warn('  failed for '.$recipient->email.': '.$e->getMessage());
                $skipped++;
            }
        }

        return [$sent, $skipped];
    }
}
