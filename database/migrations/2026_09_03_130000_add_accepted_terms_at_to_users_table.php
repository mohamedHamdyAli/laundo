<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records when somebody agreed to the terms.
 *
 * `accepted_terms` was validated on registration and then thrown away, so the
 * only evidence that anybody had ever agreed was that the request had not been
 * refused. That answers nothing if the question is ever asked — which for a
 * consumer service handling payments and holding balances, it can be.
 *
 * A timestamp rather than a boolean: «yes» is not a useful record on its own,
 * and the date is what makes it possible to say which version of the terms
 * somebody agreed to.
 *
 * Nullable, and left null for the accounts that already exist: nobody is going
 * to be told retroactively that they consented at the moment of a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('accepted_terms_at')->nullable()->after('phone_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('accepted_terms_at');
        });
    }
};
