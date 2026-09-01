<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();

            // Translatable JSON, decoded by the model accessor.
            $table->json('name');
            $table->json('description')->nullable();

            $table->string('image')->nullable();

            // Not every service is priced per piece. The design prices three
            // services per item but shows "حسب النوع والحجم" for household
            // textiles, i.e. quoted after inspection — such a service carries no
            // rows in item_prices at all.
            $table->enum('pricing_mode', ['per_item', 'quote'])->default('per_item');

            // Turnaround shown to the customer as a range: "24–48 ساعة",
            // "خلال 24 ساعة" (min = max), "2–4 أيام".
            $table->unsignedSmallInteger('duration_min')->nullable();
            $table->unsignedSmallInteger('duration_max')->nullable();
            $table->enum('duration_unit', ['hour', 'day'])->default('hour');

            $table->unsignedInteger('sort_order')->default(0);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
