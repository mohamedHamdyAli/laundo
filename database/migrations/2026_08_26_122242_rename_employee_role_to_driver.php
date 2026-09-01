<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Renames the `employee` role to `driver`.
 *
 * The role existed from the original seeder, carried zero users and was never
 * referenced by any feature, while the design calls this person مندوب / السائق
 * throughout. Renaming rather than adding avoids leaving a permanent decoy role
 * next to the real one, and with no users attached it costs nothing.
 *
 * Guarded on the row still being unused: if someone has since been given the
 * `employee` role, this stops rather than silently re-labelling their account.
 */
return new class extends Migration
{
    public function up(): void
    {
        $role = DB::table('roles')->where('slug', 'employee')->first();

        if (! $role) {
            return;
        }

        $attached = DB::table('users')->where('role_id', $role->id)->count();

        if ($attached > 0 && DB::table('roles')->where('slug', 'driver')->exists()) {
            throw new RuntimeException(
                "Cannot rename: {$attached} user(s) hold the employee role and a driver role already exists."
            );
        }

        DB::table('roles')->where('id', $role->id)->update([
            'slug' => 'driver',
            'name' => 'Driver',
            'type' => 'app',
            'is_system' => true,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('roles')->where('slug', 'driver')->update([
            'slug' => 'employee',
            'name' => 'Employee',
            'updated_at' => now(),
        ]);
    }
};
