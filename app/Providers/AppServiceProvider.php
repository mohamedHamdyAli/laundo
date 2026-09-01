<?php

namespace App\Providers;

use App\Models\Language;
use App\Services\MenuBuilder;
use App\Services\Push\PushSender;
use App\Services\Sms\SmsSender;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Resolve the SMS sender from config so call sites depend on the contract
        // and never on a concrete vendor.
        $this->app->bind(SmsSender::class, function () {
            $driver = config('sms.driver', 'log');
            $class = config("sms.drivers.{$driver}");

            if (! $class || ! class_exists($class)) {
                throw new InvalidArgumentException("Unknown SMS driver [{$driver}].");
            }

            return $this->app->make($class);
        });

        // Same shape as the SMS binding above: call sites depend on the contract,
        // never on Firebase.
        $this->app->bind(PushSender::class, function () {
            $driver = config('push.driver', 'log');
            $class = config("push.drivers.{$driver}");

            if (! $class || ! class_exists($class)) {
                throw new InvalidArgumentException("Unknown push driver [{$driver}].");
            }

            return $this->app->make($class);
        });
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

        $this->registerRateLimiters();
    }

    /**
     * Rate limiters for the API surface.
     *
     * `api` guards the whole surface. `otp` is deliberately much tighter and is
     * keyed on the phone number as well as the IP, so one number cannot be used
     * to pump out SMS from rotating addresses. It is reserved for P4 (customer
     * authentication) and defined here so the limiter exists before it is used.
     */
    protected function registerRateLimiters(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('otp', function (Request $request) {
            $key = $request->input('phone') ?: $request->ip();

            return [
                Limit::perMinute(3)->by($key),
                Limit::perDay(15)->by($key),
            ];
        });

        // Guessing a code, as opposed to requesting one. The per-account counter
        // in OtpService burns a code after a handful of misses; this stops an
        // attacker cycling accounts to stay under that counter, and is keyed on
        // the IP too so it survives a rotating phone parameter.
        RateLimiter::for('otp-verify', function (Request $request) {
            return [
                Limit::perMinute(10)->by($request->input('phone') ?: $request->ip()),
                Limit::perMinute(30)->by($request->ip()),
            ];
        });

        // Sign-in attempts. Keyed on the phone and the IP separately, so neither
        // hammering one account nor spraying many from one address gets through.
        /*
         * Location reports. The app sends one every thirty seconds — two a
         * minute — so this is fifteen times the expected rate: it exists to stop
         * a looping build from flooding the table, not to police a driver, and a
         * refused report is a driver who vanishes from the customer's map.
         */
        RateLimiter::for('location', fn (Request $request) => Limit::perMinute(30)
            ->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('login', function (Request $request) {
            return [
                Limit::perMinute(5)->by($request->input('phone') ?: $request->ip()),
                Limit::perMinute(20)->by($request->ip()),
            ];
        });
    }
}
