<?php

namespace App\Modules\Loyalty\Console;

use App\Modules\Loyalty\Models\PointTransaction;
use App\Modules\Loyalty\Services\PointsPolicy;
use App\Modules\Loyalty\Services\PointsService;
use App\Modules\Notification\Services\PointsNotifier;
use App\Modules\User\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Points that have reached their expiry, written off — and points about to,
 * announced.
 *
 * Writing off is safe to run as often as anybody likes: a lot with nothing
 * left is skipped, and each lot is written off under its own key. Nothing is
 * lost by a run that is missed either, because a spend writes off whatever has
 * lapsed before it takes anything (`PointsService::debit()`).
 *
 * The reminder goes **once per lot**, `Points_Expiry_Warning_Days` before it
 * lapses, and one message per customer per run however many lots it covers.
 */
class ExpirePoints extends Command
{
    protected $signature = 'loyalty:expire-points';

    protected $description = 'Write off lapsed loyalty points and remind customers whose points are about to expire';

    public function handle(PointsService $points, PointsPolicy $policy, PointsNotifier $notifier): int
    {
        $expired = $points->expireDue();

        $this->info("Points written off: {$expired}.");

        $warned = $this->remind($policy, $notifier);

        $this->info("Customers reminded: {$warned}.");

        return self::SUCCESS;
    }

    /**
     * One reminder per customer per run: the soonest day any of their points
     * lapse, and every point lapsing that day. A later day inside the window is
     * the next run's reminder — «30 نقطة تنتهي يوم 12» has to be true of the 12th.
     */
    private function remind(PointsPolicy $policy, PointsNotifier $notifier): int
    {
        $days = $policy->expiryWarningDays();

        // Not while the programme is paused: «استخدمها في الأوردر الجاي» is a
        // promise checkout would break, and a lot stamped now would get no
        // reminder once the programme is back on.
        if ($days < 1 || ! $policy->enabled()) {
            return 0;
        }

        $lots = PointTransaction::query()
            ->live()
            ->whereNotNull('expires_at')
            // Through the end of the business day N days ahead: points lapse at
            // the end of their day, so «7 days before» is the run seven days
            // before that day, not seven times twenty-four hours before 23:59.
            ->where('expires_at', '<=', $policy->warningHorizon())
            ->whereNull('expiry_warned_at')
            ->orderBy('expires_at')
            ->get()
            ->groupBy('user_id');

        $reminded = 0;

        foreach ($lots as $userId => $lotsOfOne) {
            /** @var Carbon $soonest */
            $soonest = $lotsOfOne->first()->expires_at;
            $day = $soonest->copy()->setTimezone(displayTimezone())->toDateString();

            $batch = $lotsOfOne->filter(
                fn (PointTransaction $lot) => $lot->expires_at->copy()->setTimezone(displayTimezone())->toDateString() === $day
            );

            // Stamped whether or not the message goes: a lot whose owner has
            // gone, or whose message failed, must not be retried every night.
            PointTransaction::whereKey($batch->modelKeys())->update(['expiry_warned_at' => now()]);

            $customer = User::find($userId);

            if (! $customer) {
                continue;
            }

            try {
                $notifier->expiring($customer, (int) $batch->sum('remaining'), $soonest);
                $reminded++;
            } catch (Throwable $e) {
                Log::warning('[points] expiry reminder failed', ['user' => $userId, 'error' => $e->getMessage()]);
            }
        }

        return $reminded;
    }
}
