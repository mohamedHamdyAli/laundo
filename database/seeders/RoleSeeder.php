<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        Role::updateOrCreate(
            ['slug' => 'super_admin'],
            ['name' => 'Super Admin', 'type' => 'dashboard', 'is_system' => true]
        );

        Role::updateOrCreate(
            ['slug' => 'admin'],
            ['name' => 'Admin', 'type' => 'dashboard']
        );

        Role::updateOrCreate(
            ['slug' => 'user'],
            ['name' => 'User', 'type' => 'app', 'is_system' => true]
        );

        Role::updateOrCreate(
            ['slug' => 'employee'],
            ['name' => 'Employee', 'type' => 'app', 'is_system' => true]
        );
    }
}
