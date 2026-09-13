<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The charges that made up one settlement's commission.
 *
 * A settlement used to carry a single `commission_rate` and a single
 * `commission_amount`, which is all one rule needs. Several stacking rules need
 * to show their working: «العمولة ٣١ ج» is a number a laundry can only accept or
 * argue with, and «١٠٪ = ٢٠ ج، زائد رسوم منصة ٥ ج، زائد ٣٪ توصيل = ٦ ج» is one
 * they can check.
 *
 * **The rule's name and terms are copied onto the line, not referenced.** The
 * same reason prices are copied onto an order: renaming «عمولة أساسية» to
 * «عمولة المنصة» next quarter, or moving it from 10% to 12%, must not restate
 * what a laundry was already charged. `commission_rule_id` is kept and is
 * nullable, so a deleted rule leaves its history readable.
 *
 * The lines always add back to `order_settlements.commission_amount` — the
 * service sums them and writes the total rather than computing it twice, so
 * they cannot drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_settlement_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_settlement_id')
                ->constrained('order_settlements')->cascadeOnDelete();

            // Nullable so deleting a rule leaves what was charged under it
            // readable, rather than deleting the evidence.
            $table->foreignId('commission_rule_id')
                ->nullable()->constrained('commission_rules')->nullOnDelete();

            // Frozen at the moment of charge. Translatable JSON, same as the
            // rule's own column, so the line reads in the operator's language
            // even after the rule is gone.
            $table->text('name');

            // percent | fixed, plus whichever value applied.
            $table->string('basis');
            $table->decimal('rate', 5, 2)->nullable();
            $table->decimal('amount', 10, 2);

            $table->timestamps();

            $table->index('order_settlement_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_settlement_lines');
    }
};
