<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fields the driver app's three record screens draw and the schema never had.
 *
 * «بيانات المركبة» asks for six things and the table held two. «رخصة القيادة»
 * asks for four and the table held three. «مستندات المركبة» draws four slots and
 * the table held one of them — the design's insurance, technical inspection and
 * «أخرى» had nowhere to go at all, which is why the app hard-coded that screen.
 *
 * **Every column is nullable, and that is the decision, not an oversight.** A
 * document is a record of what has been collected, not a precondition for having
 * an account: operations onboards a courier over the phone and photographs the
 * licence afterwards. A form that refuses until every scan is in hand means the
 * driver is not in the system on the day they start working.
 *
 * The two new expiry dates join `expiredDocuments()`, which surfaces a lapse to a
 * human and — by the decision already recorded there — does **not** stop task
 * assignment on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            // بيانات المركبة — beside the existing vehicle_type and plate_number.
            $table->string('vehicle_brand', 100)->nullable()->after('plate_number');
            $table->string('vehicle_model', 100)->nullable()->after('vehicle_brand');
            // A string rather than an integer: registration years are written
            // differently in different places, and nothing computes on it.
            $table->string('vehicle_year', 10)->nullable()->after('vehicle_model');
            $table->string('vehicle_color', 50)->nullable()->after('vehicle_year');

            // رخصة القيادة — beside license_number, license_expiry, license_image.
            $table->string('license_type', 100)->nullable()->after('license_number');
            $table->date('license_issued_at')->nullable()->after('license_type');

            // مستندات المركبة — the three slots the design draws that had no home.
            $table->string('vehicle_insurance_image')->nullable()->after('vehicle_registration_expiry');
            $table->date('vehicle_insurance_expiry')->nullable()->after('vehicle_insurance_image');
            $table->string('vehicle_inspection_image')->nullable()->after('vehicle_insurance_expiry');
            $table->date('vehicle_inspection_expiry')->nullable()->after('vehicle_inspection_image');
            // «مستندات أخرى» — optional in the design too, and deliberately
            // without an expiry: nobody knows what it is, so nothing can lapse.
            $table->string('other_document_image')->nullable()->after('vehicle_inspection_expiry');
        });
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'vehicle_brand',
                'vehicle_model',
                'vehicle_year',
                'vehicle_color',
                'license_type',
                'license_issued_at',
                'vehicle_insurance_image',
                'vehicle_insurance_expiry',
                'vehicle_inspection_image',
                'vehicle_inspection_expiry',
                'other_document_image',
            ]);
        });
    }
};
