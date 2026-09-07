<?php

namespace App\Services;

use App\Models\Language;
use App\Modules\Setting\Models\Setting;
use Illuminate\Support\Facades\Cache;

class CachingService
{
    /**
     * @param  callable  $callback  - Callback function must return a value
     * @param  int  $time  = 3600
     * @return mixed
     */
    public static function cacheRemember($key, callable $callback, int $time = 3600)
    {
        return Cache::remember($key, $time, $callback);
    }

    public static function removeCache($key)
    {
        Cache::forget($key);
    }

    /**
     * @return mixed|string
     */
    public static function getSystemSettings(array|string $key = '*')
    {
        $settings = self::cacheRemember(
            config('constants.CACHE.SETTINGS'),
            static fn () => Setting::pluck('value', 'name')
        );

        if (($key != '*')) {
            /* There is a minor possibility of getting a specific key from the $systemSettings
             * So I have not fetched Specific key from DB. Otherwise, Specific key will be fetched here
             * And it will be appended to the cached array here
             */
            $specificSettings = [];

            // If array is given in Key param
            if (is_array($key)) {
                foreach ($key as $row) {
                    if ($settings && is_array($settings) && array_key_exists($row, $settings)) {
                        $specificSettings[$row] = $settings[$row] ?? '';
                    }
                }

                return $specificSettings;
            }

            // If String is given in Key param
            if ($settings && is_object($settings) && $settings->has($key)) {
                return $settings[$key] ?? '';
            }

            return '';
        }

        return $settings;
    }

    public static function getLanguages()
    {
        return self::cacheRemember(config('constants.CACHE.LANGUAGE'), static fn () => Language::all());
    }

    /**
     * Drop the languages cache.
     *
     * This is the *other* cache — 1-hour TTL, keyed from
     * `config('constants.CACHE.LANGUAGE')` — and `clearLanguageCache()` in
     * `Helpers.php` has never touched it. Editing a language therefore left the
     * topbar switcher showing the old list and the old flags for up to an hour.
     */
    public static function forgetLanguages(): void
    {
        self::removeCache(config('constants.CACHE.LANGUAGE'));
    }

    /**
     * The language flagged `default`, not whichever one is called `en`.
     *
     * This was `where('code', 'en')->first()` — hardcoded, so it answered
     * "English" to a question about the default. The topbar reads it, which is
     * why the switcher still showed `EN` after Arabic was made the default.
     */
    public static function getDefaultLanguage()
    {
        return Language::where('default', 'true')->first();
    }
}
