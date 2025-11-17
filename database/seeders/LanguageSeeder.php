<?php

namespace Database\Seeders;

use App\Helpers\LanguageHelper;
use App\Models\Language;
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
                'default' => 'true',
                'is_rtl' => 'false',
                'icon' => 'place2.jpg',
                'app_scope' => 'user',
                'app_file' => 'app_en.json',
                'panel_file' => 'panel_en.json',
                'web_file' => 'web_en.json',
            ]
        );
        LanguageHelper::generateJsonLanguageFiles('en');

    }
}
