<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «بانتظار موافقة الإدارة» — a leg the driver confirmed with a figure that does
 * not match, held until somebody at the platform decides (the client's sheet,
 * items 9 and 10, 2026-11-08).
 *
 * - `piece_count`: the collection from the laundry counted differently from the
 *   laundry's own count.
 * - `collected_amount`: the cash taken at the door is less or more than what
 *   that leg was to take.
 *
 * One row per time a leg is held; `payload` is the confirmation exactly as the
 * driver sent it (files already stored, as paths), so an approval completes the
 * leg with nothing asked of the driver again. Reached through the order — no
 * `laundry_id`, like `order_tasks` itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_task_id')->constrained('order_tasks')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->decimal('reported', 10, 2);
            $table->decimal('expected', 10, 2)->nullable();
            $table->json('payload');
            $table->string('status', 12)->default('pending');
            $table->foreignId('raised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['status', 'kind']);
            $table->index(['order_task_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_holds');
    }
};
