<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every status change, and who caused it.
 *
 * The order's own `status` says where it is; this says how it got there. That
 * matters the moment anyone disputes a timeline — when was it collected, who
 * marked it cleaned, who cancelled it.
 *
 * `actor_id` is nullable because the system moves orders too (the recurrence
 * scheduler, and the dispatcher in P8), and pinning those on a person would be
 * a lie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();

            $table->string('from_status')->nullable();
            $table->string('to_status');

            $table->enum('actor_type', ['customer', 'driver', 'laundry', 'admin', 'system'])->default('system');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_logs');
    }
};
