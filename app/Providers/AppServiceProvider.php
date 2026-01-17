<?php

namespace App\Providers;

use App\Services\MenuBuilder;
use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Lang;
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

        View::composer('*', function ($view) {
            $view->with('dynamicMenu', MenuBuilder::build());
        });

        if (Schema::hasTable('languages')) {
            $languages = Language::all();
            View::share('available_languages', $languages);
        }

        if (Schema::hasTable('languages')) {
            View::share('available_languages', Language::all());
        }

        Lang::addJsonPath(resource_path('lang'));

    }
}
