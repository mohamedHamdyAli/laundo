<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «الأسئلة الشائعة».
 *
 * Both apps list it under «المساعدة والدعم», and until now the content had
 * nowhere to live at all — not a dashboard screen, not an endpoint, not a table.
 *
 * `audience` exists because the driver app shows the same section as the customer
 * app, and the answers are not the same: a driver asking "when do I get paid" and
 * a customer asking "when do I get my clothes" should not read each other's list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faqs', function (Blueprint $table) {
            $table->id();

            // Translatable JSON, handled manually like every other translated
            // column in this project: json_encode on write, an accessor on read.
            $table->text('question');
            $table->text('answer');

            // Which app asks. 'both' rather than a null, so the value is always a
            // deliberate choice and a query never has to reason about absence.
            $table->enum('audience', ['both', 'customer', 'driver'])->default('both');

            $table->integer('order')->default(0);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            // The only query the apps make.
            $table->index(['status', 'audience', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faqs');
    }
};
