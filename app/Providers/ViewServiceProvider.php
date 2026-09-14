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
        /*
         * The error pages decide their own language, and they have to decide it
         * *before* the view body runs.
         *
         * A 404 on a URL that matched no route never enters the `web` group — an
         * unmatched request throws before route middleware — so the locale
         * middleware never ran and the app is still on the config default. An
         * Arabic visitor who mistyped a URL got an English page.
         *
         * This cannot be fixed inside the layout. `errors/404.blade.php` does
         * `@section('title', __('Page not found'))`, and Blade evaluates the
         * child's sections before it renders the parent — so by the time the
         * layout's own `@php` block runs, every string has already been
         * translated under the old locale. A composer is the hook that fires
         * first.
         *
         * `Accept-Language` and nothing else: `CACHE_STORE` and `SESSION_DRIVER`
         * are both `database` on this install, so the session and the languages
         * table are exactly what is unavailable during the 500 these pages exist
         * to draw. A header always arrives. The list is a literal for the same
         * reason, and it is a fallback — a language missing from it costs a
         * visitor the default, not an error.
         */
        // Both spellings: the framework renders these as `errors::404` through
        // its own view namespace, while a direct `view('errors.404')` — which is
        // how the tests assert the no-query rule — resolves as `errors.404`.
        View::composer(['errors::*', 'errors.*'], static function (): void {
            $request = request();

            /*
             | The signal for «the middleware ran» is the **session**, not the
             | locale.
             |
             | Comparing `app()->getLocale()` against `config('app.locale')`
             | looks like the obvious test and is worthless: `setLocale()` writes
             | `config('app.locale')` on its way past, so the two are equal by
             | construction and the branch fires every time — forcing every
             | abort(404) in the panel to English.
             |
             | A request that reached a route has a started session and its
             | language was decided properly. One that matched nothing has no
             | session at all, and the header is all there is.
             |
             | `hasSession()` / `isStarted()` are object checks on the request.
             | Neither reads the session store, so neither touches the database.
             */
            if ($request->hasSession() && $request->session()->isStarted()) {
                return;
            }

            $preferred = $request->getPreferredLanguage(['en', 'ar']);

            if ($preferred) {
                app()->setLocale($preferred);
            }
        });

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
