<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «رسوم المشوار» — a fee per trip to the customer (the client's sheet, items 3
 * and 4, 2026-11-08).
 *
 * The delivery fee was one figure per order, the road distance from the
 * laundry times the zone's rate. Now each visit to the customer is charged on
 * its own — the pickup and the return — at one figure set from the panel
 * (`Trip_Fee`, 25), and a visit shared with another of the customer's orders
 * (same address, day and window) is charged once.
 *
 * `delivery_fee` stays the order's whole journey (the two added up), so the
 * settlement, coupons that cover the delivery, the driver's bonus and the
 * revenue report read what they always read. The two columns say how it is
 * made up. **Null on every order placed before this** — those keep the fee they
 * were placed with, and that null is what tells the code an order is priced the
 * old way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('pickup_fee', 8, 2)->nullable()->after('delivery_fee');
            $table->decimal('return_fee', 8, 2)->nullable()->after('pickup_fee');
        });

        // The figure the client named. A row so the settings screen opens on it;
        // an install that already set one keeps it.
        if (! DB::table('settings')->where('key', 'Trip_Fee')->exists()) {
            DB::table('settings')->insert([
                'key' => 'Trip_Fee',
                'value' => '25',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['pickup_fee', 'return_fee']);
        });

        DB::table('settings')->where('key', 'Trip_Fee')->delete();
    }
};
