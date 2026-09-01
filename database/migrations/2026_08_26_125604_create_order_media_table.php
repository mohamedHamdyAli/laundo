<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Photographs attached to an order.
 *
 * P6 uses `stain` only — «أضف صوراً للبقع الصعبة» in the order wizard. The
 * remaining types are the driver's proof at each handover and the laundry's
 * photos of the finished order, which arrive with P7 and P8; declaring them now
 * keeps the type list in one place rather than growing an enum later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();

            $table->enum('type', ['stain', 'pickup', 'laundry', 'ready', 'delivery'])->default('stain');
            $table->string('path');

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['order_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_media');
    }
};
