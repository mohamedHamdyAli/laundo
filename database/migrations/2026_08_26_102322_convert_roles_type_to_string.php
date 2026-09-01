<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Converts `roles.type` from an enum to a plain string, on every driver.
 *
 * The earlier widening migration used a raw MySQL `ALTER` and skipped other
 * drivers, on the assumption that `->change()` needs doctrine/dbal. That was
 * wrong for Laravel 11+, which modifies columns natively — and the assumption
 * had a real consequence: on SQLite the original `enum('dashboard','app')`
 * becomes a CHECK constraint, so inserting `laundry` failed with
 * "CHECK constraint failed: type". Every tenancy test was therefore impossible
 * to run, and any deploy on a non-MySQL driver would have broken the same way.
 *
 * A string plus the application's own validation is also simply the better fit:
 * adding the next role type stops being a schema migration at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('type', 20)->change();
        });
    }

    public function down(): void
    {
        // Deliberately not restoring the enum. Narrowing back would fail on any
        // row holding a value outside the original two, and the string is the
        // intended shape from here on.
    }
};
