<?php

namespace Database\Seeders;

use App\Models\Language;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class LanguageSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Language::updateOrCreate(
            ['code' => 'en'],
            [
                'name' => 'English',
                'name_en' => 'English',
                'country_code' => 'US',
                'is_rtl' => 'false',
                'icon' => 'place2.jpg',
                'app_scope' => 'user',
                'app_file' => 'app_en.json',
                'panel_file' => 'panel_en.json',
            ]
        );

        Language::updateOrCreate(
            ['code' => 'de'],
            [
                'name' => 'German',
                'name_en' => 'German',
                'country_code' => 'DE',
                'is_rtl' => 'true',
                'icon' => 'place1.jpg',
                'app_scope' => 'user',
                'app_file' => 'app_de.json',
                'panel_file' => 'panel_de.json',
            ]
        );
    }
}
