<?php

use App\Modules\Laundry\Models\Laundry;
use App\Modules\Payment\Enums\CommissionBasis;
use App\Modules\Payment\Models\CommissionRule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The step that stops this release quietly taking money off the platform.
 *
 * `Commission_Rate` used to mean two things at once: the general rate a laundry
 * paid when nobody had attached a rule to it, and — from this release — the fee
 * the *customer* pays. The key keeps its name and changes sides, which is fine
 * for the customer half and silent for the other: every laundry that was being
 * charged through the fallback would wake up paying nothing, with no row
 * anywhere recording that anything had changed.
 *
 * So the fallback is made explicit before it is removed. Each laundry with no
 * active rule gets one at exactly the rate it was already paying, which is what
 * `SettlementService` has always said is the honest way to express a laundry's
 * charge — «a laundry that genuinely pays nothing is expressed by attaching a
 * rule of 0, not by attaching none».
 *
 * Nothing is recomputed. Settled rows are frozen by design and pending ones
 * recompute on their own next touch, at the same figure they already carried.
 */
return new class extends Migration
{
    public function up(): void
    {
        $configured = DB::table('settings')->where('key', 'Commission_Rate')->value('value');

        if ($configured === null || $configured === '') {
            // Nothing was being charged through the fallback, so there is
            // nothing to preserve.
            return;
        }

        $rate = round(max(min((float) $configured, 100.0), 0.0), 2);

        if ($rate <= 0) {
            return;
        }

        // Matched on the whole charge, not on the name alone. `firstOrCreate`
        // keyed only on a name would happily reuse a rule that happens to be
        // called this and is inactive, or fixed-amount, or at another rate —
        // and `rulesFor()` filters on `status = active`, so an inactive one
        // would attach to every unconfigured laundry and charge them nothing.
        // That is the exact outcome this migration exists to prevent, arrived at
        // silently.
        $rule = CommissionRule::firstOrCreate([
            'name' => json_encode([
                'en' => 'General rate',
                'ar' => 'النسبة العامة',
            ], JSON_UNESCAPED_UNICODE),
            'basis' => CommissionBasis::Percent->value,
            'rate' => $rate,
            'status' => 'active',
        ]);

        // `withoutGlobalScopes` because a migration has no tenant: it runs in
        // the console, where `LaundryContext::currentId()` is null anyway, but
        // saying so here means this cannot break if that ever stops being true.
        $laundries = Laundry::withoutGlobalScopes()->get();

        foreach ($laundries as $laundry) {
            $hasRule = DB::table('commission_rule_laundry')
                ->join('commission_rules', 'commission_rules.id', '=', 'commission_rule_laundry.commission_rule_id')
                ->where('commission_rule_laundry.laundry_id', $laundry->id)
                ->where('commission_rules.status', 'active')
                ->exists();

            if ($hasRule) {
                // Already charged explicitly. Attaching the general rate on top
                // would *add* to it — rules stack — and quietly raise what this
                // laundry pays, which is the same failure in the other direction.
                continue;
            }

            $rule->laundries()->syncWithoutDetaching([$laundry->id]);
        }

        // **And the setting goes to zero.**
        //
        // The value it holds was entered to mean «what a laundry pays», and it
        // has just been written into explicit rules that say exactly that. Left
        // where it is, the very same number is read from the next request
        // onwards as the fee the *customer* pays — so every price in the
        // catalogue, on the landing page and in every quote would rise by it the
        // moment this deploys, with no operator action and nothing on any screen
        // saying why. The platform's take would double and the laundries would
        // go on paying what they always paid.
        //
        // Zero is the only value that is not a guess. The customer fee is a
        // decision somebody makes on a form whose wording now describes it, and
        // it is a decision they have not made yet.
        DB::table('settings')->where('key', 'Commission_Rate')->update(['value' => '0']);

        // And forgotten, not just written. `getSettingValue()` caches for ever
        // and `CACHE_STORE` is `database`, so the cached value outlives the
        // deploy, the restart and this migration — which writes through
        // `DB::table()` and so misses the invalidation `settingCrudService`
        // does. Without this line the safeguard above is decorative on exactly
        // the installs it exists for: the ones where the key was actually set,
        // whose cache is therefore warm at that value.
        cache()->forget('setting_Commission_Rate');
    }

    public function down(): void
    {
        // Deliberately not reversed. Detaching would restore a fallback that no
        // longer exists in the code, so every laundry this touched would go to
        // zero — the exact outcome the migration was written to prevent.
    }
};
