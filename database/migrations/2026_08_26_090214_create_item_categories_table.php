<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The grouping level between a service and an item: القمصان، التيشيرتات،
 * الملابس العلوية، الملابس السفلية، البدل، الفساتين.
 *
 * Deliberately a separate table from `categories`, which is empty and whose
 * single `default_price` column cannot express a price that varies per service.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_categories', function (Blueprint $table) {
            $table->id();
            $table->json('name');
            $table->string('image')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_categories');
    }
};
