<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The hangers an order leaves the laundry on (the client's sheet, item 11, as
 * the owner put it on 2026-11-09: «المندوب لما يستلم من المغسلة يأكد إنه استلم
 * نفس العدد اللي أكدته المغسلة»).
 *
 * - `orders.hangers_sent` — what the laundry says the clothes go out on,
 *   written when it presses «خلص الغسيل». Null on every order finished before
 *   this: nothing is checked against it.
 * - `order_tasks.hangers_collected` — what the driver says they received at
 *   the collection from the laundry. A different figure waits for the
 *   platform, like a different piece count.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('hangers_sent')->nullable();
        });

        Schema::table('order_tasks', function (Blueprint $table) {
            $table->unsignedInteger('hangers_collected')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('hangers_sent');
        });

        Schema::table('order_tasks', function (Blueprint $table) {
            $table->dropColumn('hangers_collected');
        });
    }
};
