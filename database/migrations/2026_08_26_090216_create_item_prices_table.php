<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The price matrix, and the single source of pricing truth.
 *
 * One row per (service, item). Owned by the super admin only: prices are global,
 * so a laundry never sets or sees them — it only declares which services it
 * offers (laundry_services).
 *
 * A missing row means that service is simply not priced for that item, which is
 * how a partially-filled grid is represented. Services with pricing_mode=quote
 * never get rows here.
 *
 * Prices are global today. Making them vary by city later means adding a
 * nullable city_id and widening this unique index to include it — a contained
 * migration, so the column is not added speculatively now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->decimal('price', 10, 2);
            $table->timestamps();

            $table->unique(['service_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_prices');
    }
};
