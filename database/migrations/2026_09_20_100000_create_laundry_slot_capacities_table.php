<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many orders one laundry can take in on one window.
 *
 * Deliberately **not** a column on `time_slots`. That table already carries a
 * `capacity`, and it means something different: how many *visits* the platform
 * can make in that window on that day, across every laundry, because it is
 * about vans. This one is about washing machines, and the two numbers do not
 * constrain each other.
 *
 * A missing row and a null `capacity` both mean uncapped, which is what every
 * laundry is until somebody sets a number. That is the safe default: a table
 * added to a live install must not start refusing work on the day it ships.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laundry_slot_capacities', function (Blueprint $table) {
            $table->id();

            $table->foreignId('laundry_id')->constrained('laundries')->cascadeOnDelete();
            $table->foreignId('time_slot_id')->constrained('time_slots')->cascadeOnDelete();

            // Null is uncapped, 0 is «take nothing in this window» — a laundry
            // that closes on a Friday morning needs to be able to say so, and
            // collapsing the two would leave it with no way to.
            $table->unsignedInteger('capacity')->nullable();

            $table->timestamps();

            // One number per laundry per window. Without this a double-submit on
            // the matrix screen leaves two rows and the load count silently
            // reads whichever comes back first.
            $table->unique(['laundry_id', 'time_slot_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laundry_slot_capacities');
    }
};
