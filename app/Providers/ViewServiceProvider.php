<?php

namespace App\Providers;

use App\Services\CachingService;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class ViewServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        View::composer('layouts.topbar', static function (\Illuminate\View\View $view) {
            $languages = CachingService::getLanguages();

            // `Session::get('language', CachingService::getDefaultLanguage())`
            // — and that default was hardcoded to `en`, so the switcher showed
            // `EN` until somebody clicked it, whatever the configured default
            // was. `panelLanguage()` is the one resolver the middleware and the
            // layout use, so the topbar now agrees with the page around it
            // instead of answering the same question its own way.
            $view->with([
                'languages' => $languages,
                'currentLanguage' => panelLanguage(),
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
