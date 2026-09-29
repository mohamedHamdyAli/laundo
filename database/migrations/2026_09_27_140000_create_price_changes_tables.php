<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every catalogue-wide price rise, and what each one changed.
 *
 * «محتاج يكون فيه هيستوري أقدر أشيل منه الزيادة لو حبيت ... لو اتضافت بالغلط».
 * A rise made permanent rewrites `item_prices` in place, and until now nothing
 * recorded what the prices were before — a 5% typed as 50% could only be undone
 * by retyping the whole grid.
 *
 *   - `price_changes` — one row per rise: the rate, whether it was for a period
 *     or for good, who, when, and when it ended or was undone.
 *   - `price_change_items` — for a permanent rise, each price before and after.
 *     Undoing restores the before-value **only where the price still holds the
 *     after-value**: a price somebody corrected by hand since is theirs, and
 *     restoring over it would undo their fix instead of the rise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_changes', function (Blueprint $table) {
            $table->id();
            $table->decimal('rate', 5, 2);
            // period | permanent
            $table->string('mode', 10);
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('prices_count')->default(0);
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            // A period rise taken off or replaced.
            $table->timestamp('ended_at')->nullable();
            // A permanent rise undone.
            $table->timestamp('undone_at')->nullable();
            $table->foreignId('undone_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('restored_count')->default(0);
            $table->timestamps();
        });

        Schema::create('price_change_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_change_id')->constrained('price_changes')->cascadeOnDelete();
            // By item and service rather than by the price row's id: a price
            // deleted and re-entered later is a new row with the same meaning.
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->decimal('price_before', 10, 2);
            $table->decimal('price_after', 10, 2);

            $table->index(['price_change_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_change_items');
        Schema::dropIfExists('price_changes');
    }
};
