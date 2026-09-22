<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            // Permissions first. `RoleSeeder::syncPermissions()` resolves slugs
            // against the permissions table, so a role seeded before that table
            // is filled attaches nothing at all and ships with no permissions —
            // silently, because sync([]) is not an error. On a fresh install
            // that left `driver_supervisor` empty, and with it nobody holding
            // `driver_record_submission.update`, which is the address the
            // submitted-paperwork notification is sent to.
            PermissionSeeder::class,
            RoleSeeder::class,
            AdminUserSeeder::class,
            SettingsSeeder::class,
            // After SettingsSeeder, which no longer seeds the legal copy:
            // this one owns About / Terms / Privacy_Policy and is safe to
            // re-run on its own to refresh them.
            LegalContentSeeder::class,
            LanguageSeeder::class,

        ]);
    }
}
