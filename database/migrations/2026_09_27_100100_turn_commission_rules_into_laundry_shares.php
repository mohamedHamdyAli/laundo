<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Every commission rule becomes the laundry's share, and nobody's payout moves.
 *
 * The number on a rule used to be what the platform took; from this release it
 * is what the laundry receives. The column keeps its name and changes sides —
 * the same shape of change `Commission_Rate` went through, and the same danger:
 * left as they are, a laundry on «10%» would wake up paid 10 in the hundred
 * instead of 90, with nothing on any screen recording why.
 *
 * So each laundry's terms are rewritten to say, in the new language, exactly
 * what it was already being paid:
 *
 *   - **One percentage rule** — the common case. The rule is flipped in place,
 *     10 → 90, and keeps every laundry on it.
 *   - **Several percentage rules stacked** — they no longer stack (one share per
 *     laundry, the owner's decision), and flipping each on its own would be
 *     wrong: 10% + 5% is a laundry paid 85, but 90 + 95 caps at 100. The
 *     laundry is moved to one rule carrying its combined share.
 *   - **Nothing attached** — it paid nothing, so it was paid the whole basis.
 *     It is pinned at 100 explicitly rather than left to the new general
 *     setting, which starts empty and would leave its settlements waiting.
 *   - **A fixed-amount rule** — the fixed basis is retired, and «90% less 5 a
 *     job» has no percentage that means the same thing on every order. It is
 *     not guessed at: the laundry is taken off its percentage rules so its next
 *     settlement waits for somebody to set a share, the fixed rule is switched
 *     off with its attachment kept as the record of what it was, and the
 *     laundry is named in the log.
 *
 * Settled settlements are not touched — they are frozen by design, and their
 * `laundry_share_rate` stays null to say they were divided the old way.
 * Pending ones recompute on their own next touch, at the figure they already
 * carried.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $rules = DB::table('commission_rules')->get()->keyBy('id');
            $attachments = DB::table('commission_rule_laundry')->get()->groupBy('laundry_id');
            $laundryIds = DB::table('laundries')->pluck('id');

            // Worked out in full from the state as it stands, before anything is
            // written: every decision below has to be made against the old
            // meaning of the numbers, not a half-converted mixture of both.
            $moves = [];      // laundry id => combined share, for stacked laundries
            $unruled = [];    // laundry ids that carried nothing
            $fixed = [];      // laundry ids that carried a fixed charge

            foreach ($laundryIds as $laundryId) {
                $active = collect($attachments->get($laundryId, []))
                    ->map(fn ($row) => $rules->get($row->commission_rule_id))
                    ->filter(fn ($rule) => $rule !== null && $rule->status === 'active');

                if ($active->isEmpty()) {
                    $unruled[] = $laundryId;

                    continue;
                }

                if ($active->contains(fn ($rule) => $rule->basis === 'fixed')) {
                    $fixed[] = $laundryId;

                    continue;
                }

                if ($active->count() > 1) {
                    $platform = min(100.0, $active->sum(fn ($rule) => $this->clamp($rule->rate)));
                    $moves[$laundryId] = round(100.0 - $platform, 2);
                }
            }

            $activeRuleIds = $rules->filter(fn ($rule) => $rule->status === 'active')->keys();

            // Off their stacked rules first, so that what remains attached to any
            // percentage rule is laundries carrying that rule alone — and the
            // in-place flip below is then right for every one of them.
            foreach (array_merge(array_keys($moves), $fixed) as $laundryId) {
                DB::table('commission_rule_laundry')
                    ->where('laundry_id', $laundryId)
                    ->whereIn('commission_rule_id', $activeRuleIds)
                    ->whereIn('commission_rule_id', $rules->where('basis', 'percent')->keys())
                    ->delete();
            }

            // Every percentage rule, active or not, so that switching an old one
            // back on later means what its number now says.
            foreach ($rules->where('basis', 'percent') as $rule) {
                DB::table('commission_rules')->where('id', $rule->id)->update([
                    'rate' => round(100.0 - $this->clamp($rule->rate), 2),
                    'updated_at' => now(),
                ]);
            }

            // Switched off, attachments kept: the pivot row is the only record
            // left of what these laundries had agreed to.
            DB::table('commission_rules')->where('basis', 'fixed')->update([
                'status' => 'inactive',
                'updated_at' => now(),
            ]);

            foreach ($moves as $laundryId => $share) {
                $this->attach($laundryId, $share);
            }

            foreach ($unruled as $laundryId) {
                $this->attach($laundryId, 100.0);
            }

            if ($fixed !== []) {
                $message = '[laundry-share] laundries on a fixed charge were not converted and now wait for a share: '
                    .implode(', ', $fixed);

                Log::warning($message);

                // Said in the terminal too: the person running the deploy is the
                // one who has to act on it, and the log is somewhere they will
                // look only after a laundry asks where its money went.
                if (app()->runningInConsole() && ! app()->runningUnitTests()) {
                    fwrite(STDERR, $message.PHP_EOL);
                }
            }
        });
    }

    public function down(): void
    {
        // Deliberately not reversed. The stacked and fixed cases collapse
        // several rules into one, and there is no way back to which rules a
        // laundry had from the one it has now.
    }

    /**
     * Put a laundry on a rule paying it exactly this share.
     *
     * One rule per share, reused across laundries: forty laundries on 85% are
     * one agreement written forty times, and a rule per laundry would fill the
     * rules screen with duplicates nobody could tell apart.
     */
    private function attach(int $laundryId, float $share): void
    {
        $label = rtrim(rtrim(number_format($share, 2, '.', ''), '0'), '.');

        $name = json_encode([
            'en' => "Laundry share {$label}%",
            'ar' => "نسبة المغسلة {$label}%",
        ], JSON_UNESCAPED_UNICODE);

        $ruleId = DB::table('commission_rules')
            ->where('name', $name)
            ->where('basis', 'percent')
            ->where('rate', $share)
            ->where('status', 'active')
            ->value('id');

        if ($ruleId === null) {
            $ruleId = DB::table('commission_rules')->insertGetId([
                'name' => $name,
                'basis' => 'percent',
                'rate' => $share,
                'amount' => null,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('commission_rule_laundry')->insertOrIgnore([
            'commission_rule_id' => $ruleId,
            'laundry_id' => $laundryId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function clamp(mixed $rate): float
    {
        return round(max(min((float) $rate, 100.0), 0.0), 2);
    }
};
