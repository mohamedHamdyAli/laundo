<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «رحلتك معنا بسيطة» — the three numbered how-it-works cards on the customer's
 * home screen.
 *
 * They had no source at all: not the API, not `app-settings`, and not `intros`.
 * The only way to ship them was to hardcode the copy in both apps, where nobody
 * in operations could correct a word of it and it would never be translated by
 * the same route as everything else.
 *
 * Its own table rather than a flag on `intros`, by decision: onboarding is a
 * full-screen first-run sequence somebody swipes once, and these are three
 * cards on the screen people open every day. Sharing a table would put a
 * discriminator on every query of either and give one dashboard screen two
 * unrelated jobs.
 *
 * The number the design draws beside each card is not a column — it is the
 * position, which `sort_order` already decides. Storing it twice is how a
 * «3» ends up second in the list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journey_steps', function (Blueprint $table) {
            $table->id();
            $table->text('title')->nullable();
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            $table->integer('sort_order')->default(0);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->index(['status', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journey_steps');
    }
};
