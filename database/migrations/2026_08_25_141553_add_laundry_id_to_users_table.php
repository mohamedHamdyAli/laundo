<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ties a user to the laundry they belong to.
 *
 * Null for everyone who is not laundry staff: super admins, moderators,
 * customers and drivers. The tenant scope keys off this column, so it is
 * indexed by the foreign key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('laundry_id')
                ->nullable()
                ->after('role_id')
                ->constrained('laundries')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('laundry_id');
        });
    }
};
