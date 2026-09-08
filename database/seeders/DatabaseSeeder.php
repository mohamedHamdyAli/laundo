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
            RoleSeeder::class,
            PermissionSeeder::class,
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
