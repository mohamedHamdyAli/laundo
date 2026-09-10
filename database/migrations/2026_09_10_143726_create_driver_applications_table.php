<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «عايز تشتغل مندوب؟» — somebody asking to drive, from the public page.
 *
 * Not a `users` row and not a `driver_profiles` row. A person who filled in
 * three boxes on a marketing page has not been vetted, has no licence on file
 * and has no account; writing them into `users` would put an unchecked
 * stranger in the same table as staff and would have to be undone by hand when
 * they never answer the phone. This is a lead, and it stays a lead until an
 * operator creates the driver from it.
 *
 * Three fields, because the card promises three. A recruitment form that asks
 * for a licence number is a form nobody finishes on a phone; everything else
 * is collected in the call that follows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_applications', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            // Not unique: somebody who applies twice because nobody rang back
            // is not an error to reject, it is a lead to answer faster.
            $table->string('phone');
            $table->text('note')->nullable();

            // Who dealt with it, and when. A status enum would need a fourth
            // value the day somebody wants "called, no answer"; a timestamp
            // answers the only question the list actually asks — has anybody
            // picked this up.
            $table->timestamp('handled_at')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('admin_note')->nullable();

            $table->timestamps();

            // The sidebar badge counts the unhandled ones, oldest first.
            $table->index(['handled_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_applications');
    }
};
