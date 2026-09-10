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
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
        // The fallback used to be `storage/default.png` — a path on the
        // *uploads* disk that nothing ever writes, so the placeholder for a
        // missing image was itself a missing image (403 on every row without
        // one). It is the brand mark now; see `brandPlaceholder()`.
        $imageUrl = (! empty($url) && Storage::disk('public')->exists($url))
            ? asset("storage/$url")
            : brandPlaceholder();

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

            return brandPlaceholder();
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
        /*
         * E.164, with the country code mandatory.
         *
         * This was Egypt-only — `+?201[0125]` or a bare `01[0125]`, the four
         * Egyptian mobile prefixes — while the design showed a country picker
         * with a chevron, so a customer could choose a country and then be
         * refused after typing the number. The owner's decision is to accept
         * any country, the market not being settled yet.
         *
         * The `+` is required rather than optional, deliberately: `01012345678`
         * is an Egyptian mobile *and* a valid Italian landline, and the phone is
         * the account's identity here — one that can be read two ways is one
         * that can collide, or split one person across two accounts.
         *
         * `[1-9]` after the plus because no country code begins with zero, and
         * 8–15 digits in total, which is E.164's own ceiling.
         */
        return '/^\+[1-9]\d{7,14}$/';
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

if (! function_exists('getLanguageByCode')) {
    /**
     * One language row by its code, cached.
     *
     * Uses the `language_{code}` key that `clearLanguageCache()` has always
     * forgotten — nothing ever wrote it, so that line was clearing a key that
     * did not exist. It does now.
     */
    function getLanguageByCode(string $code): ?Language
    {
        return cache()->rememberForever(
            "language_{$code}",
            fn () => Language::where('code', $code)->first()
        );
    }
}

if (! function_exists('panelLanguage')) {
    /**
     * The language the panel is showing right now.
     *
     * Four places used to answer this independently and all four hardcoded
     * `en` as the fallback: `SetLocale` did nothing without a session,
     * `layouts/main` and `auth/login` both wrote `?? 'en'`, and
     * `layouts/include` decided RTL from `code == 'ar'` and so ignored the
     * `is_rtl` column outright. The consequence was that **making a language
     * the default changed nothing about the panel** — the one thing the word
     * "default" promises.
     *
     * Resolution order, and each step is a decision:
     *
     *  1. **The session**, when it holds a language that is still a real row.
     *     Somebody who picked a language from the topbar has chosen, and an
     *     owner changing the platform default must not silently overrule them
     *     mid-session. Re-read from the database rather than trusted as
     *     serialised: the session may be days old and carrying a stale
     *     `is_rtl`, or a row that has since been deleted.
     *  2. **The default language row.** This is what was missing.
     *  3. `config('app.locale')` as a last resort, if there is no default row
     *     at all — a half-seeded install must render, not throw.
     */
    function panelLanguage(): ?Language
    {
        $chosen = Session::get('language');
        $code = is_object($chosen) ? ($chosen->code ?? null) : (is_string($chosen) ? $chosen : null);

        if (filled($code)) {
            $language = getLanguageByCode($code);

            if ($language) {
                return $language;
            }
        }

        try {
            return getDefaultLanguage();
        } catch (Throwable) {
            // `getDefaultLanguage()` throws when no row is flagged. A missing
            // default is a seeding problem, not a reason for every page to 500.
            return Language::where('code', config('app.locale'))->first();
        }
    }
}

if (! function_exists('panelLanguageCode')) {
    /**
     * Its code, for `<html lang>` and `app()->setLocale()`.
     */
    function panelLanguageCode(): string
    {
        return panelLanguage()->code ?? (string) config('app.locale');
    }
}

if (! function_exists('panelIsRtl')) {
    /**
     * Whether the panel should lay out right-to-left.
     *
     * From the `is_rtl` column — an **enum string** `'true'`/`'false'`, not a
     * boolean — rather than from `code == 'ar'`. Hebrew, Farsi or Urdu added
     * tomorrow would have rendered left-to-right under the old test.
     */
    function panelIsRtl(): bool
    {
        return (string) (panelLanguage()->is_rtl ?? 'false') === 'true';
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

if (! function_exists('brandPlaceholder')) {
    /**
     * What stands in for a row with no image.
     *
     * The full wordmark on the brand navy, on a square plate. Built by
     * compositing the two assets that already shipped rather than asked of the
     * designer: `laundo-mark.png` carries the navy (#0F2D52) and the corner
     * radius (102px of 512, ~20%), and `laundo-light.png` is the white
     * wordmark. Both were read out of those files, so the plate cannot drift
     * from the mark it matches.
     *
     * **Square, because every slot this fills is.** The 80x80 table thumbnail
     * is a fixed square, so a 560x98 wordmark handed to it raw would be
     * squashed. The wordmark sits at 76% of the plate's width — wider crowds
     * the rounded corners, narrower and the letters stop being legible once
     * this is drawn at 80px in a table row.
     *
     * Not the uploaded `App_Logo`: this install's setting still says
     * `logo1.png`, a template filename with no file behind it, so honouring it
     * would put a *broken* image in every empty row — the exact bug the old
     * `storage/default.png` fallback had.
     *
     * Falls back to the template's own placeholder if the plate is ever
     * missing, because a placeholder that 404s is worse than a generic one.
     */
    function brandPlaceholder(): string
    {
        $plate = 'assets/images/brand/laundo-placeholder.png';

        return file_exists(public_path($plate))
            ? asset($plate)
            : asset('assets/images/no_image_available.png');
    }
}

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

if (! function_exists('appCurrency')) {
    /**
     * The currency the platform charges in.
     *
     * A setting rather than a constant, because the market is not settled — the
     * same reason the phone rule now accepts any country. `getSettingValue()`
     * caches forever, so this costs no query per call, which matters: money is
     * formatted dozens of times on a single order screen.
     *
     * `EGP` is the fallback rather than `USD`, which is what the default used to
     * be — a laundry in Cairo was quoting dollars on every screen because
     * nobody had chosen.
     */
    function appCurrency(): string
    {
        $currency = strtoupper(trim((string) getSettingValue('Currency')));

        // Three letters or the fallback: an empty or half-typed setting would
        // otherwise reach NumberFormatter and render as the literal text.
        return preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'EGP';
    }
}

if (! function_exists('moneyFormat')) {
    /**
     * Money, as the current locale writes it.
     *
     * Two decisions are expressed here.
     *
     * The currency comes from the setting, not from a parameter default. It was
     * `'USD'`, and since almost nothing passed the second argument, every screen
     * in an Egyptian laundry showed dollars. The parameter survives for the rare
     * caller that genuinely means a different currency.
     *
     * `-u-nu-latn` forces Western digits. `NumberFormatter` renders `ar` with
     * Arabic-Indic numerals — ١٠٫٠٠ — which is correct by the standard and is
     * not what Egyptian apps use or what people here read prices in. The
     * extension changes only the numbering system, so the currency symbol, its
     * position and the decimal separator all stay correct for the locale.
     */
    function moneyFormat($amount, ?string $currency = null): string
    {
        $locale = getCurrentLocale().'-u-nu-latn';
        $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);

        return $formatter->formatCurrency((float) $amount, $currency ?? appCurrency());
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

// ===================================================
// =============== Web (public) Content ==============
// ===================================================

if (! function_exists('webTemplateDefaults')) {
    /**
     * The shipped web translation template, as a key => string map.
     *
     * The last link in `webText()`'s fallback chain. Held in a static rather
     * than the cache deliberately: caching it forever means an edit to
     * `webFile.php` needs a cache clear to show up, and the file is small
     * enough that `include` under opcache costs nothing. `clearLanguageCache()`
     * has never known about it and now does not need to.
     *
     * @return array<string, string>
     */
    function webTemplateDefaults(): array
    {
        static $defaults = null;

        if ($defaults !== null) {
            return $defaults;
        }

        $path = storage_path('app/webFile.php');

        if (! file_exists($path)) {
            return $defaults = [];
        }

        $loaded = include $path;

        return $defaults = is_array($loaded) ? $loaded : [];
    }
}

if (! function_exists('webText')) {
    /**
     * One string of public web copy, in the best language available.
     *
     * The reader for the Web File — the mechanism that has existed since the
     * languages screen was built and, until the landing page, had exactly one
     * consumer (`GET /api/v1/translations/web`) and no consumer inside Blade.
     *
     * The fallback chain is `pickTranslation()`'s, for the same reason: a key
     * translated in Arabic only must still render on the English page rather
     * than showing a raw `landing.hero.title` to a visitor. In order:
     *
     *   1. the current locale's `{code}_web.json`
     *   2. the default language's `{code}_web.json`
     *   3. the shipped `webFile.php` template
     *   4. `$default`, or the key itself
     *
     * Step 3 is what makes adding a key safe without a deploy dance: a key
     * added to the template renders its English default everywhere at once, and
     * each language overrides it when somebody translates it. And note the
     * dashboard's own `updateWeb()` runs `array_filter()`, so blanking a value
     * there *removes* the key and the template default takes over again — which
     * is the behaviour you want from a "reset this string" box.
     *
     * `$replace` is applied `:placeholder` style, matching `__()`, because the
     * counts and prices on the page come from the database while the sentence
     * around them comes from here.
     *
     * @param  array<string, string|int|float>  $replace
     */
    function webText(string $key, array $replace = [], ?string $default = null): string
    {
        $candidates = [(string) app()->getLocale()];

        try {
            $candidates[] = (string) getDefaultLanguage('code');
        } catch (Throwable) {
            // A half-seeded install has no default row. The template still answers.
        }

        $value = null;

        foreach (array_unique($candidates) as $code) {
            $strings = getTranslationFile('web', $code);

            if (filled($strings[$key] ?? null)) {
                $value = (string) $strings[$key];

                break;
            }
        }

        $value ??= webTemplateDefaults()[$key] ?? $default ?? $key;

        if ($replace === []) {
            return $value;
        }

        // The same three casings Laravel's own replacer handles, so `:count`,
        // `:Count` and `:COUNT` all work and a translator can open a sentence
        // with a placeholder.
        foreach ($replace as $search => $replacement) {
            $value = str_replace(
                [':'.$search, ':'.Str::ucfirst((string) $search), ':'.Str::upper((string) $search)],
                [(string) $replacement, Str::ucfirst((string) $replacement), Str::upper((string) $replacement)],
                $value
            );
        }

        return $value;
    }
}

if (! function_exists('isPlaceholderSetting')) {
    /**
     * Whether a settings value is seed data wearing a real value's clothes.
     *
     * `SettingsSeeder` fills the table with stand-ins so the dashboard has
     * something to render: `App_Name` is `BaseCode`, every social URL points at
     * the network's own front page, `Email` is the dev team's address, and
     * `About` is Latin filler. All of it is still in the live database.
     *
     * On an admin screen that is harmless. On a public page it is a footer
     * linking to facebook.com under a brand called BaseCode, so every public
     * read goes through `realSetting()` and lands here.
     *
     * Two tests, because neither alone is enough:
     *
     *   - **The exact seeded values.** Cheap, certain, and the only thing that
     *     catches `http://snapchat.com/en-GB`, which is structurally an
     *     unremarkable URL.
     *   - **A URL with no meaningful path.** `http://facebook.com/` addresses a
     *     network, not an account — nobody's profile is the bare domain. That
     *     generalises to the placeholder somebody adds next, which a fixed list
     *     cannot.
     */
    function isPlaceholderSetting(?string $value): bool
    {
        if (blank($value)) {
            return true;
        }

        $trimmed = trim((string) $value);
        $needle = rtrim(mb_strtolower($trimmed), '/');

        // Verbatim from SettingsSeeder.
        $seeded = [
            'basecode',
            'logo1.png',
            'cover.png',
            'nahrphpteam@nahrphpteam.com',
            'http://whatsapp.com',
            'http://facebook.com',
            'http://twitter.com',
            'http://instagram.com',
            'http://linkedin.com',
            'http://youtube.com',
            'http://snapchat.com/en-gb',
            'http://gmail.com',
        ];

        if (in_array($needle, $seeded, true)) {
            return true;
        }

        // Latin filler, seeded into About / Privacy_Policy / Terms.
        if (str_contains($trimmed, 'Latin gibberish') || str_contains($trimmed, 'lateinische W')) {
            return true;
        }

        // A link whose path and query are both empty addresses a front page.
        if (preg_match('#^https?://#i', $trimmed) === 1) {
            $path = trim((string) parse_url($trimmed, PHP_URL_PATH), '/');
            $query = (string) parse_url($trimmed, PHP_URL_QUERY);

            if ($path === '' && $query === '') {
                return true;
            }
        }

        return false;
    }
}

if (! function_exists('looksLikeTestCode')) {
    /**
     * Whether a coupon code is a test artifact rather than a campaign.
     *
     * This database holds `SMOKE10` from a smoke test and `PWTEST25532` /
     * `PWTEST18414` from Playwright runs, and the one published offer links to
     * the first of them. `Offer::badge()` renders the linked coupon's discount,
     * so publishing that offer unguarded puts a smoke-test coupon's value on
     * the front page — and, worse, advertises a code a customer could try.
     *
     * A deny-list rather than a convention, because the codes were not written
     * to be recognisable: `PW` is Playwright's prefix by habit, not by rule. It
     * is deliberately loose — a real campaign would not be called `TESTDROP`,
     * and refusing to badge one is a smaller failure than publishing a fixture.
     *
     * Applied to the badge only. The offer's own copy («باقة غسيل البطاطين») is
     * real marketing text an operator wrote, and it still renders.
     */
    function looksLikeTestCode(?string $code): bool
    {
        if (blank($code)) {
            return true;
        }

        $needle = strtoupper(trim((string) $code));

        foreach (['TEST', 'SMOKE', 'DUMMY', 'SAMPLE', 'FIXTURE', 'DEBUG'] as $marker) {
            if (str_contains($needle, $marker)) {
                return true;
            }
        }

        // Playwright's specs mint codes prefixed `PW`.
        return (bool) preg_match('/^PW[A-Z0-9]*\d{3,}$/', $needle);
    }
}

if (! function_exists('realSetting')) {
    /**
     * A settings value, or null when it is blank or still seed data.
     *
     * The one read the public pages use. Returning null rather than the string
     * lets a template simply not render the row — which is the difference
     * between a footer with two contact methods and a footer with two contact
     * methods and a dead link to gmail.com.
     */
    function realSetting(string $key): ?string
    {
        $value = getSettingValue($key);

        return isPlaceholderSetting(is_string($value) ? $value : null)
            ? null
            : trim((string) $value);
    }
}

if (! function_exists('landingCtaTarget')) {
    /**
     * Where the landing page's primary button actually goes.
     *
     * There is no public sign-up (`Auth::routes(['register' => false])`) and no
     * store listing yet, so this cannot be a hardcoded href. It resolves down a
     * chain and reports which rung it landed on, so the template can render a
     * store pair, a chat link, a phone number or an in-page jump — and never a
     * button that goes nowhere.
     *
     * The last rung is the prices section rather than `#`, because a visitor who
     * came to find out what it costs is better served by the price list than by
     * a dead click.
     *
     * @return array{kind: string, href: string, stores: array<string, string>}
     */
    function landingCtaTarget(): array
    {
        $appStore = realSetting('App_Store_Url');
        $playStore = realSetting('Play_Store_Url');

        if ($appStore !== null || $playStore !== null) {
            return [
                'kind' => 'store',
                'href' => (string) ($appStore ?? $playStore),
                'stores' => array_filter([
                    'ios' => $appStore,
                    'android' => $playStore,
                ]),
            ];
        }

        if (($whatsapp = realSetting('Whats_App')) !== null) {
            return ['kind' => 'whatsapp', 'href' => $whatsapp, 'stores' => []];
        }

        foreach (['Hotline', 'Call'] as $key) {
            if (($phone = realSetting($key)) !== null) {
                // Spaces and dashes are for reading, not for dialling.
                return [
                    'kind' => 'phone',
                    'href' => 'tel:'.preg_replace('/[^\d+]/', '', $phone),
                    'stores' => [],
                ];
            }
        }

        return ['kind' => 'anchor', 'href' => '#prices', 'stores' => []];
    }
}

if (! function_exists('landingAssetVersion')) {
    /**
     * A cache-busting token for a file under `public/assets`.
     *
     * The landing page's CSS and JS are served straight out of `public/` — this
     * project has no build step for them, and `npm run build` covers only the
     * seven views that extend `layouts.app`. So there is no content hash in the
     * filename to invalidate a cache, and a deploy that changes a stylesheet
     * leaves returning visitors on the one they already have.
     *
     * `filemtime()` changes exactly when the file does and costs a stat. Falls
     * back to the app version rather than throwing, so a missing file renders a
     * broken link instead of a 500 — the wrong asset is a visible bug, an
     * exception on the front page is an outage.
     */
    function landingAssetVersion(string $relativePath): string
    {
        $absolute = public_path('assets/'.ltrim($relativePath, '/'));

        return file_exists($absolute)
            ? (string) filemtime($absolute)
            : (string) app()->version();
    }
}

if (! function_exists('assetVersion')) {
    /**
     * The same stamp, for the panel's own hand-edited assets.
     *
     * `landingAssetVersion()` was written for the landing page and then the
     * panel turned out to have the identical problem, twice over on one
     * deploy: `theme.css` and `custom.js` are edited by hand, have no build
     * step and no content hash, and sit behind Cloudflare. A release that
     * changed both shipped Blade that referred to rules and behaviour the
     * cached files did not have — the sidebar's new badges rendered as bare
     * numbers, and the splash, which is `position: fixed` in the new
     * stylesheet, painted as a full-size image across the top of every page.
     *
     * Same implementation, honest name. The old one is kept because four
     * views already call it and it is not wrong, only narrowly named.
     */
    function assetVersion(string $relativePath): string
    {
        return landingAssetVersion($relativePath);
    }
}
