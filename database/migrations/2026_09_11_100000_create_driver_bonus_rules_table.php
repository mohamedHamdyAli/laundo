<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a driver is paid on top of their salary.
 *
 * The salary itself is **not in this system at all** — the owner's decision:
 * «ملناش دعوة بيه خالص». It is paid outside, so nothing here records or holds
 * one. What the platform owns is the bonus, and this is the shape of it.
 *
 * A **rule**, not a number on the driver. Almost every driver is on the same
 * terms, and a rule they share is a rule that can be changed once; a rate copied
 * onto three hundred profiles is three hundred rows to find when the terms move.
 * The driver points at a rule, and a driver pointing at nothing earns nothing —
 * which replaces a hardcoded 20% that nobody could see or stop.
 *
 * Two families of bonus live on one row because they are one agreement:
 *
 *   - the **immediate** part — `basis` plus `amount`/`rate` — paid as the driver
 *     works, and
 *   - the **monthly** part — the tiers in `driver_bonus_tiers`, gated by the
 *     three `min_`/`max_` columns here.
 *
 * The gates are the reason the monthly half exists in this shape. A bonus paid
 * purely on volume pays a driver to rush, and rushing is damaged clothes and
 * wrong addresses. All three gates are measured from columns the application
 * already writes and nothing has ever read — `order_tasks.due_at`,
 * `order_ratings.delivery`, and the failed-task count.
 *
 * Every gate is nullable, and null means «not applied». Zero does not: a
 * `max_failed_tasks` of 0 is a real and very strict rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_bonus_rules', function (Blueprint $table) {
            $table->id();

            // Translatable: {"en":"…","ar":"…"} in a text column, decoded by the
            // model accessor. `text` and not `json` deliberately — the seven
            // json columns in this database compare as binary and broke
            // lowercase search on their own list screens.
            $table->text('name');

            // per_order | per_task | percent_delivery_fee
            $table->string('basis')->index();

            // A flat sum, for per_order and per_task.
            $table->decimal('amount', 10, 2)->nullable();

            // A percentage of the delivery fee, for percent_delivery_fee. Stored
            // as the number an operator types — 15 means 15%, matching every
            // other rate on the settings screen.
            $table->decimal('rate', 5, 2)->nullable();

            /*
             * The monthly gates. Null is «no gate» everywhere here, and the
             * distinction from zero is load-bearing: a minimum on-time rate of 0
             * passes everybody, and null does not evaluate at all.
             */
            $table->decimal('min_on_time_rate', 5, 2)->nullable();
            $table->decimal('min_delivery_rating', 3, 2)->nullable();
            $table->unsignedInteger('max_failed_tasks')->nullable();

            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_bonus_rules');
    }
};
