<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coordinates for a laundry.
 *
 * Required by the delivery-fee rule: the fee is the distance from the laundry to
 * the customer's address multiplied by the pickup zone's per-kilometre rate. The
 * table only had a text address, so there was nothing to measure from.
 *
 * Nullable, because the two existing laundries predate this and an admin has to
 * fill them in. A laundry without coordinates cannot price a delivery, which the
 * fee calculator reports rather than guessing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laundries', function (Blueprint $table) {
            $table->decimal('lat', 10, 7)->nullable()->after('address');
            $table->decimal('lng', 10, 7)->nullable()->after('lat');
        });
    }

    public function down(): void
    {
        Schema::table('laundries', function (Blueprint $table) {
            $table->dropColumn(['lat', 'lng']);
        });
    }
};
