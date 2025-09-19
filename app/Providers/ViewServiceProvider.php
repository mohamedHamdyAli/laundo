<?php

namespace App\Providers;

use App\Services\CachingService;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\View;

class ViewServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        View::composer('layouts.topbar', static function (\Illuminate\View\View $view) {
            $languages = CachingService::getLanguages();
            $defaultLanguage = CachingService::getDefaultLanguage();

            // Get current language from session or set to default if not set
            $currentLanguage = Session::get('language', $defaultLanguage);
            $view->with([
                'languages' => $languages,
                'currentLanguage' => $currentLanguage
            ]);
            // $view->with('languages', CachingService::getLanguages() );
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
