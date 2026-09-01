<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «تتبع المندوب مباشرة» — where the driver is, right now.
 *
 * Three columns, not a table. The owner's decision was the last point only: two
 * screens in the design draw a single moving dot, and none of them draws a trail.
 * A points table would be the largest table in the system inside a month — a
 * driver reporting every thirty seconds over an eight-hour shift is about a
 * thousand rows a day, each of them — for the question the screens actually ask —
 * obsolete thirty seconds after it is written.
 *
 * On `driver_profiles` rather than `users` because only drivers have a position,
 * and `users` is shared with customers, staff and admins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            // 7 decimal places is about a centimetre. More would be storing GPS
            // noise; fewer loses the difference between two sides of a street.
            $table->decimal('last_lat', 10, 7)->nullable()->after('is_available');
            $table->decimal('last_lng', 10, 7)->nullable()->after('last_lat');
            // The reading is worthless without its age: a stationary dot from
            // twenty minutes ago is read as "the driver has stopped".
            $table->timestamp('located_at')->nullable()->after('last_lng');
        });
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropColumn(['last_lat', 'last_lng', 'located_at']);
        });
    }
};
