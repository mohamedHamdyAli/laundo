<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The driver's operational record.
 *
 * Every field maps to a row in the design's driver account screen:
 *
 *   بيانات المركبة      vehicle_type, plate_number
 *   رخصة القيادة        license_number, license_expiry, license_image
 *   مستندات المركبة     vehicle_registration_image / _expiry, national_id_image
 *   أوقات العمل         shift_start, shift_end
 *   استقبال الطلبات     is_available
 *
 * A separate table rather than more columns on `users`, because none of this
 * applies to customers, laundry staff or moderators — who are all rows in the
 * same table.
 *
 * Expiry dates are recorded and surfaced as a dashboard warning; by decision
 * they do not automatically stop assignment, so no enforcement lives here.
 *
 * One shift window per driver applies to every working day, matching the
 * decision taken. Per-weekday hours later would be an additive table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();

            $table->string('vehicle_type')->nullable();
            $table->string('plate_number')->nullable();

            $table->string('license_number')->nullable();
            $table->date('license_expiry')->nullable();
            $table->string('license_image')->nullable();

            $table->string('vehicle_registration_image')->nullable();
            $table->date('vehicle_registration_expiry')->nullable();
            $table->string('national_id_image')->nullable();

            $table->time('shift_start')->nullable();
            $table->time('shift_end')->nullable();

            // The «متاح لاستقبال المهام» switch. Off by default: a newly created
            // driver should not start receiving work before anyone says so.
            $table->boolean('is_available')->default(false);

            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_profiles');
    }
};
