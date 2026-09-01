<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «تقديم شكوى».
 *
 * Two entry points in the design, and they are not the same shape: the driver app
 * lists it under «المساعدة والدعم» in the account screen — a general complaint —
 * while the customer reaches «المساعدة والدعم» from an order's detail screen, so
 * theirs usually names an order. `order_id` is nullable to hold both.
 *
 * Operations answers by phone, per the owner's decision, so there is no reply
 * thread here. What there is instead: a status the complainant can see, an
 * internal note they cannot, and a record of who handled it and when. A complaint
 * that lands and shows nothing back is a black hole, and the status is the
 * cheapest honest thing to show.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complaints', function (Blueprint $table) {
            $table->id();

            // Human-quotable. Operations answers by phone, so the first thing said
            // on that call is a reference, and «رقم 47» is not one.
            $table->string('reference')->unique();

            // Customer or driver — both apps offer this.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Null for a general complaint. Not every problem is about an order.
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();

            // Denormalised from the order, for "which laundry generates the most
            // complaints". Deliberately NOT scoped by BelongsToLaundry: the owner
            // decided complaints are the platform's to handle, so a laundry never
            // reads them. It is here to be reported on, not to be filtered by.
            $table->foreignId('laundry_id')->nullable()->constrained('laundries')->nullOnDelete();

            $table->string('category');
            $table->text('body');

            $table->string('status')->default('new');

            // Internal. Never returned by the customer API — the note is where
            // operations writes what was actually said on the phone.
            $table->text('internal_note')->nullable();

            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();

            $table->timestamps();

            // The queue, oldest open first.
            $table->index(['status', 'created_at']);
            // «أكتر سبب شكوى إيه» — the only reason the category is a closed set.
            $table->index(['category', 'created_at']);
            $table->index(['laundry_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaints');
    }
};
