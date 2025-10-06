<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use App\Models\Language;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrapFive();
        Schema::defaultStringLength(191);

        if (Schema::hasTable('languages')) {
            $languages = Language::all();
            View::share('available_languages', $languages);
        }

        try {
            $locale = App::getLocale();
            $langPath = resource_path('lang');

            $files = [
                "{$locale}.json",
                "{$locale}_mobile.json",
                "{$locale}_web.json",
            ];

            $translations = [];

            foreach ($files as $file) {
                $filePath = "{$langPath}/{$file}";
                if (File::exists($filePath)) {
                    $content = json_decode(File::get($filePath), true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($content)) {
                        $translations = array_merge($translations, $content);
                    }
                }
            }

            $panelFile = "{$langPath}/{$locale}.json";

            if (File::exists($panelFile)) {
                $panelTranslations = json_decode(File::get($panelFile), true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($panelTranslations)) {
                    $translations = array_merge($translations, $panelTranslations);
                }
            }

            if (!empty($translations)) {
                Lang::addLines($translations, $locale);
            }
        } catch (\Throwable $e) {
            Log::error('Translation merge error: ' . $e->getMessage());
        }
    }
}
