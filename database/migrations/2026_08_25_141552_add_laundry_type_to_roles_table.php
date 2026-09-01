<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widens roles.type from ('dashboard','app') to include 'laundry'.
 *
 * There is no doctrine/dbal in this project, so ->change() cannot alter an enum;
 * the column is modified with a raw statement instead. SQLite (used by the test
 * suite) has no native enum, so the statement is skipped there — the column is
 * already an unconstrained varchar.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE `roles` MODIFY COLUMN `type` ENUM('dashboard','app','laundry') NOT NULL");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Rows carrying the value being removed would otherwise be silently
        // truncated to ''. Fail loudly instead of corrupting them.
        if (Schema::hasTable('roles') && DB::table('roles')->where('type', 'laundry')->exists()) {
            throw new RuntimeException(
                'Cannot roll back: roles with type=laundry still exist. Reassign or delete them first.'
            );
        }

        DB::statement("ALTER TABLE `roles` MODIFY COLUMN `type` ENUM('dashboard','app') NOT NULL");
    }
};
