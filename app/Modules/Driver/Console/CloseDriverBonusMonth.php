<?php

namespace App\Modules\Driver\Console;

use App\Modules\Driver\Models\DriverBonusAward;
use App\Modules\Driver\Services\MonthlyBonusService;
use App\Modules\Notification\Data\NotificationMessage;
use App\Modules\Notification\Enums\NotificationEvent;
use App\Modules\Notification\Models\NotificationLog;
use App\Modules\Notification\Services\NotificationDispatcher;
use App\Modules\User\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Close a month's driver bonuses and tell somebody they are waiting.
 *
 * The screen already recomputes any open month every time it is opened, so this
 * command is not what makes the figures correct — it is what makes them
 * **noticed**. A bonus nobody is reminded of is a bonus paid late, and a driver
 * chasing last month's money is the cheapest possible way to lose them.
 *
 * **It computes and notifies. It never approves.** Approving is a person's act
 * by design — «الاسترداد الموافق عليه بس هو اللي بيتصرف» is the same rule, and a
 * monthly payout that ran on a schedule would be a wrong payment made in the
 * month nobody was looking. What this does is put the month in front of somebody
 * on the morning it becomes final.
 *
 * Defaults to **last month**, because that is the one that is over. Run on the
 * 1st, «this month» would be a month one day old and every figure in it wrong.
 *
 * Sends **once per period, ever** — the notification log is the memory. An alert
 * that repeats daily until somebody acts teaches people to dismiss it, and then
 * the one that mattered is dismissed too.
 */
class CloseDriverBonusMonth extends Command
{
    protected $signature = 'drivers:close-bonus-month
        {--period= : The month to close, as YYYY-MM. Defaults to last month.}
        {--quiet-notify : Compute without notifying anybody}';

    protected $description = 'Work out last month driver bonuses and tell operations they are waiting';

    public function handle(MonthlyBonusService $bonuses, NotificationDispatcher $dispatcher): int
    {
        $month = $this->resolveMonth($bonuses);

        if ($month === null) {
            $this->error('--period must be a real month, as YYYY-MM.');

            return self::FAILURE;
        }

        $period = $bonuses->period($month);
        $written = $bonuses->computePeriod($month);

        $this->info("Closed {$period}: {$written} driver(s) measured.");

        if ($written === 0) {
            // Nobody is on a rule. Not a fault — a driver on no rule earns no
            // bonus, and that is the default — so there is nothing to raise.
            return self::SUCCESS;
        }

        // Only what is actually waiting on a person: a month where every driver
        // missed their target is a month with nothing to decide, and an alert
        // about it is noise.
        $waiting = DriverBonusAward::where('period', $period)
            ->due()
            ->where('amount', '>', 0)
            ->get();

        if ($waiting->isEmpty()) {
            $this->info("Nothing is owed for {$period}.");

            return self::SUCCESS;
        }

        if ($this->option('quiet-notify')) {
            return self::SUCCESS;
        }

        if ($this->alreadyRaised($period)) {
            $this->info("Operations has already been told about {$period}.");

            return self::SUCCESS;
        }

        $operators = $this->operators();

        if ($operators->isEmpty()) {
            $this->warn('No super admin to notify.');

            return self::SUCCESS;
        }

        $dispatcher->sendMany($operators, new NotificationMessage(
            event: NotificationEvent::DriverBonusReady,
            title: __('Driver bonuses are waiting for you'),
            body: __(':count driver bonuses for :period total :amount. Nothing is paid until you approve them.', [
                'count' => $waiting->count(),
                'period' => $period,
                'amount' => moneyFormat($waiting->sum('amount')),
            ]),
            url: '/admin/driver-bonus?period='.$period,
            data: ['period' => $period],
            // The subject is what makes «once per period» checkable: the log
            // records what was said about what, so a second column recording
            // the same fact would be a second source of one truth.
            subject: $waiting->first(),
        ));

        $this->info("Told {$operators->count()} operator(s) about {$waiting->count()} bonus(es).");

        return self::SUCCESS;
    }

    private function resolveMonth(MonthlyBonusService $bonuses): ?CarbonImmutable
    {
        $option = $this->option('period');

        if ($option === null) {
            return CarbonImmutable::now()->subMonth()->startOfMonth();
        }

        return $bonuses->parsePeriod((string) $option);
    }

    /**
     * Whether operations has already been told about this month.
     *
     * Keyed on the **subject**, not on the message payload: `notification_logs`
     * stores `subject_type`/`subject_id` and has no column for `data`, so a
     * `data->period` lookup would match nothing and the alert would repeat every
     * single day. Any logged message pointing at any award in this period means
     * this month has been raised — which is stable however many awards there
     * are and whichever one happened to be the subject.
     */
    private function alreadyRaised(string $period): bool
    {
        return NotificationLog::where('event', NotificationEvent::DriverBonusReady->value)
            ->where('subject_type', DriverBonusAward::class)
            ->whereIn('subject_id', DriverBonusAward::where('period', $period)->select('id'))
            ->exists();
    }

    /**
     * @return Collection<int, User>
     */
    private function operators(): Collection
    {
        return User::whereHas('role', fn ($q) => $q->where('slug', 'super_admin'))->get();
    }
}
