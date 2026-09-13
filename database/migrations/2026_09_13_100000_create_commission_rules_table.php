<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A charge the platform makes on a laundry's order.
 *
 * It replaces `laundries.commission_rate` — one nullable percentage per laundry
 * — because one number could only ever express one agreement. A real contract
 * is «10% of the order, plus 5 EGP a job», and expressing that as a single
 * percentage means recomputing it every time the average order value moves.
 *
 * **A named rule, and a laundry may carry several.** They are attached through
 * `commission_rule_laundry` and their results are **added together**: the
 * owner's decision — «تتجمع على بعض». Each one lands as its own line on the
 * settlement, so a laundry disputing what it was charged is shown three
 * arithmetic steps rather than one blended number it cannot reproduce.
 *
 * `basis` decides which of the two value columns is read, and the service nulls
 * the other on save: a rule carrying both a percentage and a flat amount is a
 * rule with two answers, and whichever the calculator read first would silently
 * become the truth. Same shape as `driver_bonus_rules`, deliberately — two
 * money tables that behave differently are two sets of rules to remember.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_rules', function (Blueprint $table) {
            $table->id();

            // Translatable: {"en":"…","ar":"…"} in a text column, decoded by the
            // model accessor. `text` and not `json` — the seven json columns in
            // this database compare as binary and broke lowercase search on
            // their own list screens.
            $table->text('name');

            // percent | fixed
            $table->string('basis')->index();

            // A share of the order total before tax.
            $table->decimal('rate', 5, 2)->nullable();

            // A flat charge per order, whatever the order came to.
            $table->decimal('amount', 10, 2)->nullable();

            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });

        Schema::create('commission_rule_laundry', function (Blueprint $table) {
            $table->id();

            $table->foreignId('commission_rule_id')
                ->constrained('commission_rules')->cascadeOnDelete();
            $table->foreignId('laundry_id')
                ->constrained('laundries')->cascadeOnDelete();

            $table->timestamps();

            // One laundry cannot carry the same charge twice. Without this a
            // double-submitted form would quietly double what it pays.
            $table->unique(['commission_rule_id', 'laundry_id'], 'commission_rule_laundry_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_rule_laundry');
        Schema::dropIfExists('commission_rules');
    }
};
