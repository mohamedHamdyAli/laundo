<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Carry `laundries.commission_rate` into a rule, then remove the column.
 *
 * Two places that answer «what does this laundry pay» is one too many, and the
 * one left behind is the one somebody eventually edits. So the column goes.
 *
 * **A rate of 0 becomes a 0% rule, not an absence of rules.** This is the whole
 * care in this migration: under the new model a laundry with nothing attached
 * falls back to the general rate, so dropping a 0 as «nothing to migrate» would
 * silently start charging a laundry that had negotiated its way out of the
 * commission entirely. Null is the one that means «follow the general rate» and
 * is the only value with nothing to carry.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('laundries', 'commission_rate')) {
            return;
        }

        $laundries = DB::table('laundries')
            ->whereNotNull('commission_rate')
            ->get(['id', 'name', 'commission_rate']);

        foreach ($laundries as $laundry) {
            $rate = round((float) $laundry->commission_rate, 2);

            // Named after the laundry, because that is what it is: a rate that
            // belonged to one of them and to nobody else. An operator can rename
            // it or attach it elsewhere afterwards.
            $decoded = json_decode((string) $laundry->name, true);
            $label = is_array($decoded) ? ($decoded['en'] ?? reset($decoded)) : (string) $laundry->name;

            $ruleId = DB::table('commission_rules')->insertGetId([
                'name' => json_encode([
                    'en' => trim($label.' rate'),
                    'ar' => 'نسبة '.trim((string) ($decoded['ar'] ?? $label)),
                ], JSON_UNESCAPED_UNICODE),
                'basis' => 'percent',
                'rate' => $rate,
                'amount' => null,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('commission_rule_laundry')->insert([
                'commission_rule_id' => $ruleId,
                'laundry_id' => $laundry->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('laundries', function (Blueprint $table) {
            $table->dropColumn('commission_rate');
        });
    }

    public function down(): void
    {
        Schema::table('laundries', function (Blueprint $table) {
            $table->decimal('commission_rate', 5, 2)->nullable()->after('status');
        });

        // Reversed only where a laundry carries exactly one percentage rule —
        // the shape the column could hold. A laundry on two rules, or on a flat
        // charge, has no single rate to write back, and inventing one would be
        // worse than leaving it null for somebody to look at.
        $single = DB::table('commission_rule_laundry')
            ->select('laundry_id', DB::raw('count(*) as attached'))
            ->groupBy('laundry_id')
            ->having('attached', '=', 1)
            ->pluck('laundry_id');

        foreach ($single as $laundryId) {
            $rule = DB::table('commission_rule_laundry')
                ->join('commission_rules', 'commission_rules.id', '=', 'commission_rule_laundry.commission_rule_id')
                ->where('commission_rule_laundry.laundry_id', $laundryId)
                ->where('commission_rules.basis', 'percent')
                ->first(['commission_rules.rate']);

            if ($rule !== null) {
                DB::table('laundries')->where('id', $laundryId)->update(['commission_rate' => $rule->rate]);
            }
        }
    }
};
