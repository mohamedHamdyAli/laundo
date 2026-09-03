<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «ملاحظة للمندوب» on the address.
 *
 * The design shows it inside the address card, and the only note like it was
 * `orders.driver_note` — attached to one order. «Call before arriving», a gate
 * code, «the bell on the left doesn't work»: those are properties of the place.
 * Written once on the address, every order to it carries them; written on the
 * order, the customer retypes them every time and forgets on the one delivery
 * where it matters.
 *
 * Not `notes`, which already exists and means «ملاحظات العنوان» per the
 * original migration's own field list — a description of the address for the
 * person who wrote it. This is an instruction to somebody standing outside.
 *
 * `orders.driver_note` stays: an instruction about one order («the black bag is
 * my neighbour's») is not an instruction about the address.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->string('driver_note', 500)->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->dropColumn('driver_note');
        });
    }
};
