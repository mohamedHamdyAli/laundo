<?php

namespace Database\Seeders;

use App\Services\PermissionGenerator;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        (new PermissionGenerator)->generate();
    }
}
