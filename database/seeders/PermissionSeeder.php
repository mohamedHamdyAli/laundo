<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Services\PermissionGenerator;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        (new PermissionGenerator())->generate();
    }
}
