<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One driver's monthly bonus, and the numbers it was decided on.
 *
 * A separate table from `driver_earnings` because it could not be anything else:
 * that table's `basis` and `rate` are NOT NULL and its `order_task_id` is a
 * required foreign key with a unique index. A month has no task, no basis and no
 * rate — it has a count, three measurements and a threshold.
 *
 * **The measurements are stored, not recomputed.** Same rule as prices on an
 * order and the rate on a settlement: a driver asking why September paid 500 and
 * October nothing has to be shown the four numbers the decision was made from,
 * and a late-arriving rating or a reopened task must not silently restate a
 * month that has already been paid.
 *
 * `gate_failures` names which condition blocked it, in the driver's own terms.
 * «Rejected» with no reason is how an operator ends up re-deriving the
 * arithmetic by hand in a conversation with somebody who is angry.
 *
 * **Nothing here pays itself.** A row is written `due`; money moves only when a
 * person approves it. The same rule as refunds — «الاسترداد الموافق عليه بس هو
 * اللي بيتصرف» — and for the same reason: an automatic monthly payout is a wrong
 * payment made in a month when nobody was looking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_bonus_awards', function (Blueprint $table) {
            $table->id();

            $table->foreignId('driver_id')->constrained('users')->cascadeOnDelete();

            // Which terms produced this. Nullable so deleting a rule leaves the
            // history readable rather than deleting what was already paid.
            $table->foreignId('driver_bonus_rule_id')
                ->nullable()->constrained('driver_bonus_rules')->nullOnDelete();

            // 'YYYY-MM'. A string and not a date because that is exactly what it
            // is — a month, with no day that would invite a range query to treat
            // it as one.
            $table->string('period', 7)->index();

            // What the decision was made from, frozen at the moment it was made.
            $table->unsignedInteger('orders_count')->default(0);
            $table->decimal('on_time_rate', 5, 2)->nullable();
            $table->decimal('avg_delivery_rating', 3, 2)->nullable();
            $table->unsignedInteger('failed_tasks')->default(0);

            // The tier that applied, so the row explains its own amount.
            $table->unsignedInteger('tier_min_orders')->nullable();
            $table->decimal('amount', 10, 2)->default(0);

            // due | approved | rejected
            $table->string('status')->default('due')->index();

            // Which gate stopped it, if one did.
            $table->json('gate_failures')->nullable();

            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();

            $table->timestamps();

            // One decision per driver per month. A recomputed month updates its
            // row; it does not add a second one, and it cannot pay twice.
            $table->unique(['driver_id', 'period']);
            $table->index(['period', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_bonus_awards');
    }
};
