<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «طلبات خارج التغطية» — customers who tried to order to or from an address we
 * do not serve.
 *
 * The app tells them «we will contact you as soon as we reach your area», and
 * this is what makes that sentence true: without a record of who asked, there
 * is nobody to ring. One row per customer and address, not per attempt — the
 * review screen re-quotes as the customer changes their mind, and a call list
 * with the same person on it ten times is a call list nobody reads. The count
 * says how keen they were.
 *
 * The pin and the street are copied in: the customer can delete or edit the
 * address afterwards, and «where were they» is the whole point of the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coverage_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // Null once the customer deletes the address; the copy below stays.
            $table->foreignId('address_id')->nullable()->constrained('addresses')->nullOnDelete();

            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->string('address_line', 500)->nullable();

            $table->unsignedInteger('attempts')->default(1);
            $table->timestamp('last_attempt_at');

            // Somebody rang them. A timestamp rather than a status, the same
            // as `driver_applications.handled_at`: the only question the list
            // asks is whether anybody has.
            $table->timestamp('contacted_at')->nullable();
            $table->foreignId('contacted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One row per customer and address — a second attempt counts on
            // the first row. The index also makes that lookup cheap.
            $table->unique(['user_id', 'address_id']);
            $table->index(['contacted_at', 'last_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coverage_requests');
    }
};
