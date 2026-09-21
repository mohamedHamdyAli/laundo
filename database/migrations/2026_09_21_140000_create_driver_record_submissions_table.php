<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a driver has sent about their vehicle or their papers, waiting on a person.
 *
 * The driver app can now edit «بيانات المركبة», «رخصة القيادة» and «مستندات
 * المركبة». Writing those straight onto `driver_profiles` makes the licence
 * expiry whatever the driver last typed, and a record nobody checks is not a
 * record — so the submission is staged here and applies on approval.
 *
 * `payload` is the submitted fields only, never the whole profile: a driver who
 * saved one screen must not have the other two silently re-asserted at whatever
 * they were when the form loaded.
 *
 * **One pending row per driver.** A second submission supersedes the first rather
 * than queueing two versions of the same car — an operator working through a
 * backlog of a driver's own corrections is reviewing history, not decisions.
 *
 * Uploaded files are written on submit and their paths live in the payload. A
 * rejection leaves the file where it is: a refused photograph is evidence of what
 * was sent, and deleting it means the next conversation has nothing to look at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_record_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('users')->cascadeOnDelete();

            $table->json('payload');

            // A plain string, like every other status in this codebase, and for
            // the reason `convert_roles_type_to_string` records: an enum column
            // makes adding the next state a schema migration, and SQLite refuses
            // the constraint outright, which made the tenancy tests impossible.
            $table->string('status', 20)->default('pending')->index();

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            // Why it was refused. The driver sees it, or they send the same
            // photograph again and nobody has told them what was wrong with it.
            $table->text('note')->nullable();

            $table->timestamps();

            // The queue's own query: what is waiting, oldest first.
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_record_submissions');
    }
};
