<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the customer thought.
 *
 * The whole rating flow is designed in Figma — an overall score, three aspects,
 * a set of tags and a free-text box — and none of it existed. Every report so far
 * measures speed and disputes; nothing measured whether the customer was happy.
 *
 * One row per order. The button in the design sits on a completed order, and a
 * second rating for the same order would mean the aggregate depends on how many
 * times somebody tapped it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_ratings', function (Blueprint $table) {
            $table->id();

            // Unique, not just indexed: one rating per order is the rule, and
            // enforcing it here means a double-tap cannot skew a laundry's average.
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Denormalised from the order on purpose. It is what BelongsToLaundry
            // scopes on, so a laundry owner reads only their own ratings without a
            // join; and an average that had to reach through `orders` would be a
            // scope nobody remembers to apply.
            $table->foreignId('laundry_id')->nullable()->constrained('laundries')->nullOnDelete();

            // 1..5, matching the five stars in the design. The overall score is
            // the only required one — the design offers «تخطي», and a customer who
            // gives four stars and skips the detail has still told us something.
            $table->unsignedTinyInteger('overall');
            $table->unsignedTinyInteger('service_quality')->nullable();
            $table->unsignedTinyInteger('delivery')->nullable();
            $table->unsignedTinyInteger('timing')->nullable();

            // «ما الذي أعجبك؟» — the chips. A JSON list of keys rather than a
            // pivot table: they are a fixed, short, presentational set, and the
            // only question ever asked of them is "how often was this one picked".
            $table->json('tags')->nullable();

            // «اكتب ملاحظاتك أو شكواك هنا...» — the placeholder says complaint, so
            // a low score with a comment is a support case, not just a number.
            $table->text('comment')->nullable();

            $table->timestamps();

            // The two questions the reports ask: this laundry's scores, and the
            // recent low ones somebody has to answer.
            $table->index(['laundry_id', 'overall']);
            $table->index(['overall', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_ratings');
    }
};
