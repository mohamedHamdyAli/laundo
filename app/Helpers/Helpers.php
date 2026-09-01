<?php

use App\Models\Language;
use App\Models\Role;
use App\Modules\Setting\Models\Setting;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

// ===================================================
// =============== Upload & Image Helpers ============
// ===================================================

if (! function_exists('uploadOrUpdateImage')) {
    /**
     * Upload or update image with validation (ext + size).
     */
    function uploadOrUpdateImage(?UploadedFile $image, string $directory, ?string $existingImagePath = null): ?string
    {
        if ($image) {
            $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (! in_array(strtolower($image->extension()), $allowedExt)) {
                throw new HttpResponseException(response()->json(['msg' => 'Invalid image type'], 422));
            }

            if ($image->getSize() > 5 * 1024 * 1024) {
                throw new HttpResponseException(response()->json(['msg' => 'Image size too large'], 422));
            }

            if ($existingImagePath && Storage::disk('public')->exists($existingImagePath)) {
                Storage::disk('public')->delete($existingImagePath);
            }

            return $image->store($directory, 'public');
        }

        return $existingImagePath;
    }
}

if (! function_exists('DeleteImage')) {
    /**
     * Delete image from storage.
     */
    function DeleteImage(?string $existingImagePath): bool
    {
        if ($existingImagePath && Storage::disk('public')->exists($existingImagePath)) {
            return Storage::disk('public')->delete($existingImagePath);
        }

        return false;
    }
}

if (! function_exists('getImageDashboardUrl')) {
    /**
     * Get image url with preview HTML (for dashboard tables).
     */
    function getImageDashboardUrl(?string $url): string
    {
        $imageUrl = (! empty($url) && Storage::disk('public')->exists($url))
            ? asset("storage/$url")
            : asset('storage/default.png');

        return "<a href='{$imageUrl}' target='_blank'>
                    <img class='rounded-circle' style='height:80px;width:80px;border-radius:10%;' src='{$imageUrl}'>
                </a>";
    }
}

if (! function_exists('getImageassetUrl')) {
    /**
     * Get image asset url (for multiple or single).
     */
    function getImageassetUrl($urls)
    {
        $getUrl = function ($url) {
            if (! empty($url) && Storage::disk('public')->exists($url)) {
                return asset("storage/$url");
            }
            if (! empty($url) && file_exists(public_path($url))) {
                return asset($url);
            }

            return asset('storage/default.png');
        };

        return is_array($urls) ? array_map($getUrl, $urls) : $getUrl($urls);
    }
}

// ===================================================
// =============== Validation Helpers ================
// ===================================================

if (! function_exists('phoneRegex')) {
    /**
     * The one place the accepted phone format is defined.
     *
     * Egyptian mobile numbers: 11 digits locally (01 + operator digit + 8), or the
     * same number internationally as +20 / 20 with the leading zero dropped.
     * Accepts 01012345678, +201012345678 and 201012345678; rejects everything else.
     *
     * Return value is the bare pattern, so callers prefix it with 'regex:' for a
     * validation rule.
     */
    function phoneRegex(): string
    {
        return '/^(?:\+?201[0125]\d{8}|01[0125]\d{8})$/';
    }
}

if (! function_exists('failedValidation')) {
    /**
     * Return custom validation error response.
     */
    function failedValidation($validator): never
    {
        $errors = collect($validator->errors()->toArray())->flatten()->first();
        $response = response()->json([
            'key' => 'Invalid data sent',
            'msg' => $errors,
            'code' => 422,
        ]);

        throw new HttpResponseException($response);
    }
}

// ===================================================
// =============== Localization Helpers ==============
// ===================================================

if (! function_exists('getCurrentLocale')) {
    /**
     * Get current locale from request or default.
     */
    function getCurrentLocale(): string
    {
        $locale = request()->header('lang') ?? app()->getLocale();

        $availableLocales = cache()->rememberForever(
            'available_locales',
            fn () => Language::pluck('code')->toArray()
        );

        if (! in_array($locale, $availableLocales)) {
            $locale = getDefaultLanguage('code');
        }

        // Set Carbon locale globally
        Carbon::setLocale($locale);

        return $locale;
    }
}

if (! function_exists('pickTranslation')) {
    /**
     * The best available translation of one value, in a fixed order of preference.
     *
     * Shared by the API and the dashboard so the fallback rule exists once. It
     * used to be written inline in both as `?? ->ar`, which hardcoded Arabic as
     * the last resort even on an install whose default language is English.
     *
     * The chain matters more than the first choice. A laundry whose Arabic name
     * was never filled in must not vanish from an Arabic panel — a row that reads
     * "No Data Found" where a name should be is unsearchable, unreadable, and
     * looks like data loss. So: the asked-for language, then the default, then
     * whatever is actually there.
     *
     * @param  object|null  $translations  a stdClass, as the model accessors return
     */
    function pickTranslation(?object $translations, string $preferred, string $fallbackMessage): string
    {
        if ($translations === null) {
            return $fallbackMessage;
        }

        $values = (array) $translations;

        foreach ([$preferred, (string) getDefaultLanguage('code')] as $code) {
            if (filled($values[$code] ?? null)) {
                return (string) $values[$code];
            }
        }

        // Anything rather than nothing.
        foreach ($values as $value) {
            if (filled($value)) {
                return (string) $value;
            }
        }

        return $fallbackMessage;
    }
}

if (! function_exists('getLocalizedValue')) {
    /**
     * Get localized attribute for API.
     */
    function getLocalizedValue($model, string $attribute): string
    {
        if (! $model || ! $model->{$attribute}) {
            return __('no_data_found');
        }

        return pickTranslation($model->{$attribute}, getCurrentLocale(), __('no_data_found'));
    }
}

if (! function_exists('getLocalizedValueDashboard')) {
    /**
     * Get a translated attribute for the dashboard, in the panel's own language.
     *
     * It used to return the **default** language regardless of what the operator
     * had the panel set to, which was the convention from the first phase. The
     * owner reversed that decision: an Arabic panel shows Arabic data.
     *
     * `pickTranslation` supplies the fallback chain, and it is what makes the
     * reversal safe — a value missing in the panel's language shows in another
     * rather than disappearing.
     */
    function getLocalizedValueDashboard($model, string $attribute): string
    {
        if (! $model || ! $model->{$attribute}) {
            return 'No Data Found';
        }

        // The session language, which is what the topbar switcher sets.
        $locale = app()->getLocale();

        return pickTranslation($model->{$attribute}, $locale, 'No Data Found');
    }
}
if (! function_exists('replaceLanguageFile')) {
    /**
     * Take an uploaded translation file into resources/lang.
     *
     * It used to delete the target and move the upload into its place. For
     * `panel_file` the target is `{code}.json` — the panel's complete translation,
     * currently 1,076 entries — so an admin uploading a partial file from the
     * languages screen silently destroyed the lot, with no warning and no undo.
     *
     * Now the upload is **merged into** what is already there, and the uploaded
     * values win per key. Adding twenty strings adds twenty strings; it cannot
     * remove a thousand. A malformed file is refused before anything is written.
     */
    function replaceLanguageFile(Language $language, string $field, $file): void
    {
        $langPath = resource_path('lang');
        $code = $language->code;

        $map = [
            'panel_file' => "{$code}.json",
            'app_file' => "{$code}_mobile.json",
            'web_file' => "{$code}_web.json",
        ];

        if (! isset($map[$field])) {
            return;
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());
        if (! in_array($extension, ['json', 'txt'], true)) {
            throw new Exception('Invalid file type: must be .json or .txt');
        }

        // Decoded and checked before the existing file is touched. A truncated or
        // malformed upload must not be able to leave the target half-written.
        $incoming = json_decode((string) file_get_contents($file->getRealPath()), true);

        if (! is_array($incoming)) {
            throw new Exception('The file is not valid JSON.');
        }

        $targetFile = "{$langPath}/{$map[$field]}";

        $existing = [];
        if (File::exists($targetFile)) {
            $decoded = json_decode(File::get($targetFile), true);
            $existing = is_array($decoded) ? $decoded : [];
        }

        // Uploaded values win per key; every key not mentioned survives.
        $merged = array_merge($existing, $incoming);

        File::put(
            $targetFile,
            json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL
        );

        // The reader caches for ever, so a stale copy would outlive the upload.
        clearLanguageCache($code);
    }
}

// ===================================================
// =============== Auth Helpers ======================
// ===================================================

if (! function_exists('userAuth')) {
    /**
     * Get authenticated user if role = user.
     */
    function userAuth()
    {
        $user = auth('api')->user();

        return ($user && strtolower($user->role->slug ?? '') === Role::USER) ? $user : null;
    }
}

if (! function_exists('isAdmin')) {
    /**
     * Check if user is admin.
     */
    function isAdmin($user = null): bool
    {
        $user ??= auth('api')->user();

        return $user && strtolower($user->role->slug ?? '') === Role::ADMIN;
    }
}
if (! function_exists('isDriver')) {
    /**
     * Check if the API-authenticated user is a driver.
     *
     * Was isEmployee() against Role::EMPLOYEE. Both were renamed with the role
     * itself; nothing referenced either, so there is no call site to update.
     */
    function isDriver($user = null): bool
    {
        $user ??= auth('api')->user();

        return $user && strtolower($user->role->slug ?? '') === Role::DRIVER;
    }
}

// ===================================================
// =============== Language & Settings ===============
// ===================================================

if (! function_exists('getDefaultLanguage')) {
    /**
     * Get default language (cached).
     */
    function getDefaultLanguage($col_name = null)
    {
        $language = cache()->rememberForever(
            'default_language',
            fn () => Language::where('default', 'true')->first()
        );

        if (! $language) {
            throw new Exception('Language not found.');
        }

        return $col_name ? $language->{$col_name} : $language;
    }
}

if (! function_exists('getAllLanguageWithoutDefault')) {
    /**
     * Get all languages except default (cached).
     */
    function getAllLanguageWithoutDefault()
    {
        return cache()->rememberForever(
            'languages_without_default',
            fn () => Language::where('default', 'false')->get()
        );
    }
}

if (! function_exists('getTranslationFile')) {
    /**
     * Read a translation JSON file and cache its array content.
     * $type: panel|app|web|default
     * $code: language code like 'en', 'ar'
     */
    function getTranslationFile(string $type, string $code): array
    {
        $code = strtolower($code);
        $cacheKey = "lang_file_{$code}_{$type}";

        return cache()->rememberForever($cacheKey, function () use ($type, $code) {
            $fileName = match ($type) {
                'panel' => "{$code}_panel.json",
                'app' => "{$code}_mobile.json",
                'web' => "{$code}_web.json",
                default => "{$code}.json",
            };

            $jsonFile = base_path("resources/lang/{$fileName}");

            if (! File::exists($jsonFile)) {
                return []; // empty array if file not exists
            }

            $content = File::get($jsonFile);

            return json_decode($content, true) ?? [];
        });
    }
}

if (! function_exists('clearLanguageCache')) {
    function clearLanguageCache(?string $code = null): void
    {
        // clear general caches
        clearCacheHelpers();

        // clear specific language caches
        if ($code) {
            cache()->forget("language_{$code}");
            cache()->forget("lang_file_{$code}_panel");
            cache()->forget("lang_file_{$code}_app");
            cache()->forget("lang_file_{$code}_web");
            cache()->forget("lang_file_{$code}_default");
        }
    }
}

if (! function_exists('rebuildLanguageCache')) {
    /**
     * Rebuild all language-related caches.
     */
    function rebuildLanguageCache(): void
    {
        clearCacheHelpers();

        Cache::rememberForever('all_languages', fn () => Language::all());
        Cache::rememberForever('available_locales', fn () => Language::pluck('code')->toArray());
        Cache::rememberForever('default_language', fn () => Language::where('default', 'true')->first());
        Cache::rememberForever('languages_without_default', fn () => Language::where('default', 'false')->get());
    }
}

if (! function_exists('getSettingValue')) {
    /**
     * Get setting value (cached).
     */
    function getSettingValue($key)
    {
        return cache()->rememberForever(
            "setting_{$key}",
            fn () => Setting::where('key', $key)->value('value')
        );
    }
}

if (! function_exists('brandLogo')) {
    /**
     * The logo, in the variant that is actually visible on the given surface.
     *
     * There are two files because the brand mark is navy `#072555`, and the
     * sidebar and login brand panel are navy too — the mark measures **1.08:1**
     * on them, which is not "faint", it is gone. `light` is the same artwork in
     * white, at 13.84:1.
     *
     * The choice lives here rather than in each Blade file so that a template
     * cannot pick the wrong one, and so an uploaded logo and the bundled default
     * are resolved the same way in both directions.
     *
     * @param  'dark'|'light'  $variant  dark for white surfaces, light for navy
     */
    function brandLogo(string $variant = 'dark'): string
    {
        $uploaded = $variant === 'light'
            ? getSettingValue('App_Logo_Light')
            : getSettingValue('App_Logo');

        // Not just "is the setting set" — "is the file actually there". This
        // install shipped with App_Logo = 'logo1.png' from the template and no
        // such file, so honouring the setting rendered a broken image. A setting
        // pointing at a deleted upload is the same case and will happen again.
        if (filled($uploaded) && Storage::disk('public')->exists($uploaded)) {
            return asset('storage/'.$uploaded);
        }

        // The bundled brand asset. Trimmed and padded from the designer's export,
        // which was 1000x1000 with the wordmark 558x75 and sitting low — used raw
        // it renders tiny and off-centre inside its box.
        return asset('assets/images/brand/laundo-'.($variant === 'light' ? 'light' : 'dark').'.png');
    }
}

// ===================================================
// =============== Utility Helpers ===================
// ===================================================

if (! function_exists('clearCacheHelpers')) {
    /**
     * Clear all cached helper values.
     */
    function clearCacheHelpers()
    {
        cache()->forget('default_language');
        cache()->forget('languages_without_default');
        cache()->forget('available_locales');
        cache()->forget('all_languages');
    }
}

if (! function_exists('formatFileSize')) {
    /**
     * Format bytes into KB, MB, GB.
     */
    function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2).' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2).' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2).' KB';
        }

        return $bytes.' B';
    }
}

if (! function_exists('humanDate')) {
    /**
     * Human-readable date (diffForHumans or formatted).
     */
    function humanDate($date, ?string $format = null): string
    {
        if (! $date) {
            return __('no_data_found');
        }

        $locale = getCurrentLocale();

        // Stored values are UTC. Shift into the display timezone here — this is
        // the only place a timezone conversion belongs, because doing it in the
        // application timezone would corrupt what gets written back.
        $carbonDate = Carbon::parse($date)
            ->setTimezone(displayTimezone())
            ->locale($locale);

        return $format ? $carbonDate->translatedFormat($format) : $carbonDate->diffForHumans();
    }
}

if (! function_exists('displayTimezone')) {
    /**
     * The timezone humans reading the dashboard expect to see.
     *
     * Set per request by SetTimezone from the configured country, and falling
     * back to the application timezone when no country is configured. Never used
     * for storage or comparison — only for rendering.
     */
    function displayTimezone(): string
    {
        return (string) config('app.display_timezone', config('app.timezone', 'UTC'));
    }
}

if (! function_exists('randomCode')) {
    /**
     * Generate random alphanumeric code.
     */
    function randomCode(int $length = 6): string
    {
        return strtoupper(substr(bin2hex(random_bytes($length)), 0, $length));
    }
}

if (! function_exists('moneyFormat')) {
    /**
     * Format money by locale & currency.
     */
    function moneyFormat($amount, string $currency = 'USD'): string
    {
        $locale = getCurrentLocale();
        $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);

        return $formatter->formatCurrency($amount, $currency);
    }
}

if (! function_exists('canDo')) {
    function canDo(string $permission): bool
    {
        $user = Auth::user();

        if (! $user || ! $user->role) {
            return false;
        }

        // Super Admin bypass
        if ($user->role->slug === 'super_admin') {
            return true;
        }

        return $user->role
            ->permissions
            ->contains('slug', $permission);
    }
}
