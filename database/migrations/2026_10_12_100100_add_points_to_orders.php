<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an order spent and earned in points, and the hangers a driver brought
 * back.
 *
 * Copied onto the order at placement and never re-read, the rule the tax rate
 * and the coupon's terms follow: a point worth a pound today must not be worth
 * fifty piastres on an order placed this morning because the setting moved at
 * noon.
 *
 *  - `points_used` / `points_discount` — what the customer spent and what it
 *    took off. The discount is the platform's, all of it (the owner's call); the
 *    laundry's share is worked out as if there were no points.
 *  - `points_to_earn` — what completing the order pays, shown at checkout.
 *  - `points_terms` — the rest of the terms in force: the point's value, the
 *    cap on how much of the washing points may pay for, and the minimum order
 *    that earns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('points_used')->default(0)->after('discount_scope');
            $table->decimal('points_discount', 10, 2)->default(0)->after('points_used');
            $table->unsignedInteger('points_to_earn')->default(0)->after('points_discount');
            $table->json('points_terms')->nullable()->after('points_to_earn');
        });

        Schema::table('order_tasks', function (Blueprint $table) {
            // «شماعات راجعة» — counted by the driver at the customer's door.
            // Null is «not asked», which is not the same as none handed back.
            $table->unsignedSmallInteger('hangers_returned')->nullable()->after('piece_count');
        });

        Schema::table('order_settlements', function (Blueprint $table) {
            // Stored beside `discount_amount`, not folded into it: the coupon
            // has a bearer to divide it by and points do not — they are the
            // platform's.
            $table->decimal('points_discount_amount', 12, 2)->default(0)->after('laundry_discount_amount');
        });
    }

    public function down(): void
    {
        Schema::table('order_settlements', function (Blueprint $table) {
            $table->dropColumn('points_discount_amount');
        });

        Schema::table('order_tasks', function (Blueprint $table) {
            $table->dropColumn('hangers_returned');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['points_used', 'points_discount', 'points_to_earn', 'points_terms']);
        });
    }
};
