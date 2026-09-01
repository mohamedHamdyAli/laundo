<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The four physical journeys an order makes.
 *
 * An order is a promise; these are the movements that keep it. The delivery app
 * is built around exactly four, each its own screen:
 *
 *   1. استلام من العميل    — collect from the customer
 *   2. تسليم للمغسلة       — hand over to the laundry
 *   3. استلام من المغسلة   — collect the finished order
 *   4. تسليم للعميل        — return it to the customer
 *
 * `sequence` is what makes the chain safe: a task cannot start until the one
 * before it has completed, so nothing can be delivered that was never collected.
 * Storing the order rather than deriving it from `type` means the rule is
 * enforceable with a comparison instead of a lookup table.
 *
 * `driver_id` is nullable throughout, not only before the first dispatch. A task
 * whose driver failed it goes back to null and re-enters the queue — the same
 * state as one never assigned, which is exactly what it is.
 *
 * `collected_amount` is the driver's «المبلغ المحصل» on the last leg. It is
 * recorded even when it disagrees with what was owed: by decision the delivery
 * completes and the discrepancy is surfaced, because a driver standing at the
 * customer's door is the worst possible place to argue about five pounds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();

            $table->string('type');
            $table->unsignedTinyInteger('sequence');
            $table->string('status')->default('pending')->index();

            $table->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();

            // The window the task is due in, copied from the order's slot. «متأخرة»
            // in the app is nothing more than now() being past `due_at`.
            $table->timestamp('due_at')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // What the driver counted at this handover. Deliberately separate from
            // the laundry's count in P7: two people counting the same clothes is
            // the point, not a duplication.
            $table->unsignedInteger('piece_count')->nullable();

            // «اسم الموظف المستلم» on the hand-over to the laundry.
            $table->string('receiver_name')->nullable();

            // «توقيع العميل» — legs 1 and 4 only. A laundry hands over to a
            // colleague, not to a customer.
            $table->string('signature_path')->nullable();

            // «المبلغ المحصل» on the final leg.
            $table->decimal('collected_amount', 10, 2)->nullable();

            $table->string('failure_reason')->nullable();
            $table->text('failure_note')->nullable();
            // Counts failures across drivers, not per driver: two failures is a
            // problem with the task, whoever was holding it.
            $table->unsignedTinyInteger('attempts')->default(0);

            $table->text('note')->nullable();
            $table->timestamps();

            // One of each leg per order, enforced rather than trusted — a
            // double-generated chain would put two drivers at one door.
            $table->unique(['order_id', 'type']);

            // The driver app's task list and the dispatch queue read these.
            $table->index(['driver_id', 'status']);
            $table->index(['status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_tasks');
    }
};
