<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orders already carrying a coupon when the laundry share was turned round keep
 * the payout they had.
 *
 * Before 2026-09-27 the platform took its cut of the washing *after* the
 * discount, so the laundry bore its own share of every coupon: on a 100 wash
 * with a 20 coupon and a 90% share, the laundry was paid (100 − 20) × 90% = 72.
 * The new split measures the laundry's share before the discount and takes off
 * only the part a coupon names — and an order placed before that choice
 * existed names none, which reads as «the platform bears it»: 90, not 72. Every
 * discounted order still in flight would have paid its laundry more and the
 * platform less, and nothing would have said so.
 *
 * Bearing the discount in the proportion of the laundry's own share gives back
 * exactly the old figure — share × (base − discount) — so that is what those
 * orders are stamped with. Only orders not yet settled: money that has moved is
 * never restated. A laundry with no share at all is skipped, because its
 * settlement waits for one anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $general = DB::table('settings')->where('key', 'Laundry_Share_Rate')->value('value');
            $general = is_numeric($general) ? (float) $general : null;

            // The share each laundry is on now: its oldest active percentage
            // rule, as SettlementService::ruleFor() picks it.
            $shares = DB::table('commission_rule_laundry as attached')
                ->join('commission_rules as rules', 'rules.id', '=', 'attached.commission_rule_id')
                ->where('rules.status', 'active')
                ->where('rules.basis', 'percent')
                ->orderBy('rules.id')
                ->get(['attached.laundry_id', 'rules.rate'])
                ->groupBy('laundry_id')
                ->map(fn ($rows) => (float) $rows->first()->rate);

            $orders = DB::table('orders')
                ->whereNull('discount_laundry_share')
                ->where('discount_total', '>', 0)
                ->whereNotNull('laundry_id')
                ->whereNotExists(fn ($query) => $query
                    ->select(DB::raw(1))
                    ->from('order_settlements')
                    ->whereColumn('order_settlements.order_id', 'orders.id')
                    ->whereIn('order_settlements.status', ['settled', 'cancelled']))
                ->get(['id', 'laundry_id']);

            $stamped = [];

            foreach ($orders as $order) {
                $share = $shares[$order->laundry_id] ?? $general;

                if ($share === null) {
                    continue;
                }

                DB::table('orders')->where('id', $order->id)->update([
                    'discount_laundry_share' => round(max(min($share, 100.0), 0.0), 2),
                ]);

                $stamped[] = $order->id;
            }

            if ($stamped !== []) {
                Log::info('[migration] coupon share stamped on orders in flight, so their payout stays as it was', [
                    'orders' => $stamped,
                ]);
            }
        });
    }

    /**
     * Nothing to put back: which orders were stamped here is in the log, and a
     * rollback that guessed would restate payouts twice.
     */
    public function down(): void {}
};
