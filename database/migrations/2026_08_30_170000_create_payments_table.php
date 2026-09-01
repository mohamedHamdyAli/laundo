<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per attempt to pay for an order.
 *
 * An attempt, not a payment — a customer whose card is declined and who then pays
 * by wallet has two rows, and both matter: the first is why they called support,
 * the second is why the order shipped. Storing only the successful one throws away
 * the half of the history anybody ever asks about.
 *
 * `provider_reference` is «رقم المعاملة» on the design's confirmation screen. It
 * is unique per provider because that is the key a webhook arrives with, and the
 * uniqueness is what makes a replayed webhook a no-op instead of a double capture.
 *
 * `payload` keeps whatever the provider sent. Deliberately kept verbatim: when a
 * settlement disagrees with our figures six weeks later, the provider's own words
 * are the only thing that settles it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('provider');
            $table->string('method');

            // Null until the provider has told us what it calls this.
            $table->string('provider_reference')->nullable();

            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('EGP');

            $table->string('status')->default('pending')->index();

            $table->timestamp('authorised_at')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_reason')->nullable();

            $table->json('payload')->nullable();

            $table->timestamps();

            // The webhook's idempotency key. A provider may legitimately send the
            // same event twice; it must not be able to capture twice.
            $table->unique(['provider', 'provider_reference']);
            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
