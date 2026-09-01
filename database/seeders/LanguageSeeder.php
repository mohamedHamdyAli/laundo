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

        // Arabic is the language the apps are designed in, and getLocalizedValue()
        // already falls back to ->ar, so the row has to exist for that fallback to
        // resolve. Left as non-default on purpose: flipping the default language
        // changes what the whole dashboard renders in, which is a business call.
        Language::updateOrCreate(
            ['code' => 'ar'],
            [
                'name' => 'العربية',
                'name_en' => 'Arabic',
                'country_code' => 'EG',
                'default' => 'false',
                'is_rtl' => 'true',
                'app_scope' => 'user',
                'app_file' => 'app_ar.json',
                'panel_file' => 'panel_ar.json',
                'web_file' => 'web_ar.json',
            ]
        );
        LanguageHelper::generateJsonLanguageFiles('ar');
    }
}
