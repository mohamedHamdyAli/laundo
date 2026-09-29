<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A laundry asking to start, or stop, offering a service.
 *
 * Until now a laundry owner ticked its services and they applied at once. The
 * client wants each change approved: «لو طلب انو يقفل او يفتح سيرفس يجي طلب
 * موافقه للسوبر ادمن ... غير بعد الموافقه». Which services a laundry offers
 * decides which orders `LaundryAssigner` hands it, so a laundry switching one on
 * is a laundry claiming work, and switching one off mid-week is orders with
 * nowhere to go.
 *
 * **Nothing here takes effect by existing.** `laundry_services` is still the
 * truth the assigner reads; a request changes it only when somebody approves.
 * Until then a laundry that asked to open a service receives no orders in it,
 * and one that asked to close a service keeps receiving them.
 *
 * **One pending request per laundry and service.** Asking again replaces the
 * earlier one rather than queueing two answers to the same question; the old
 * row is marked `superseded`, not deleted, so the history of who asked for what
 * survives. A rejection carries a note, and the note reaches the laundry — told
 * only «rejected», it would send the same request again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laundry_service_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laundry_id')->constrained('laundries')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();

            // open | close
            $table->string('action', 10);

            // pending | approved | rejected | superseded
            $table->string('status', 12)->default('pending')->index();

            // Required on a rejection, and shown to the laundry beside the service.
            $table->text('note')->nullable();

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            $table->index(['laundry_id', 'service_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laundry_service_requests');
    }
};
