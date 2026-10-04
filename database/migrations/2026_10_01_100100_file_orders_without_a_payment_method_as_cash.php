<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * An order with no payment method is a cash order (the owner, 2026-10-01).
 *
 * The app sent none on 23 of the 31 live orders. `OrderService` now reads a
 * missing method as cash; this files the orders placed before it the same way.
 * A card attempt always writes its method onto the order, so none of these
 * ever went near a gateway. The cash fee is not added: it is copied onto an
 * order at placement and never re-read, and these were placed without it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $moved = DB::table('orders')->whereNull('payment_method')->update(['payment_method' => 'cash']);

        Log::info("[payments] {$moved} orders with no payment method filed as cash.");
    }

    public function down(): void
    {
        // Not reversed: which orders had no method is not worth restoring.
    }
};
