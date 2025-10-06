<?php

use App\Models\Language;
use App\Modules\Setting\Models\Setting;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

// ===================================================
// =============== Upload & Image Helpers ============
// ===================================================

if (!function_exists('uploadOrUpdateImage')) {
    /**
     * Upload or update image with validation (ext + size).
     */
    function uploadOrUpdateImage(?UploadedFile $image, string $directory, ?string $existingImagePath = null): ?string
    {
        if ($image) {
            $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (!in_array(strtolower($image->extension()), $allowedExt)) {
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

if (!function_exists('DeleteImage')) {
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

if (!function_exists('getImageDashboardUrl')) {
    /**
     * Get image url with preview HTML (for dashboard tables).
     */
    function getImageDashboardUrl(?string $url): string
    {
        $imageUrl = (!empty($url) && Storage::disk('public')->exists($url))
            ? asset("storage/$url")
            : asset('storage/default.png');

        return "<a href='{$imageUrl}' target='_blank'>
                    <img class='rounded-circle' style='height:80px;width:80px;border-radius:10%;' src='{$imageUrl}'>
                </a>";
    }
}

if (!function_exists('getImageassetUrl')) {
    /**
     * Get image asset url (for multiple or single).
     */
    function getImageassetUrl($urls)
    {
        $getUrl = function ($url) {
            if (!empty($url) && Storage::disk('public')->exists($url)) {
                return asset("storage/$url");
            }
            if (!empty($url) && file_exists(public_path($url))) {
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

if (!function_exists('failedValidation')) {
    /**
     * Return custom validation error response.
     */
    function failedValidation($validator)
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

if (!function_exists('getCurrentLocale')) {
    /**
     * Get current locale from request or default.
     */
    function getCurrentLocale(): string
    {
        $locale = request()->header('lang') ?? app()->getLocale();

        $availableLocales = cache()->rememberForever(
            'available_locales',
            fn() => Language::pluck('code')->toArray()
        );

        if (!in_array($locale, $availableLocales)) {
            $locale = getDefaultLanguage('code');
        }

        // Set Carbon locale globally
        Carbon::setLocale($locale);

        return $locale;
    }
}

if (!function_exists('getLocalizedValue')) {
    /**
     * Get localized attribute for API.
     */
    function getLocalizedValue($model, string $attribute): string
    {
        if (!$model || !$model->{$attribute}) {
            return __('no_data_found');
        }

        $locale = getCurrentLocale();
        return $model->{$attribute}->{$locale} ?? $model->{$attribute}->ar;
    }
}

if (!function_exists('getLocalizedValueDashboard')) {
    /**
     * Get localized attribute for dashboard (default language).
     */
    function getLocalizedValueDashboard($model, string $attribute): string
    {
        if (!$model || !$model->{$attribute}) {
            return 'No Data Found';
        }

        $locale = getDefaultLanguage('code');
        return $model->{$attribute}->{$locale} ?? $model->{$attribute}->ar;
    }
}
if (!function_exists('replaceLanguageFile')) {
    function replaceLanguageFile(Language $language, string $field, $file): void
    {
        $langPath = resource_path('lang');
        $code = $language->code;

        // تحديد اسم الملف حسب النوع
        $map = [
            'panel_file' => "{$code}.json",
            'app_file'   => "{$code}_mobile.json",
            'web_file'   => "{$code}_web.json",
        ];

        if (!isset($map[$field])) {
            return;
        }

        $targetFile = "{$langPath}/{$map[$field]}";

        // حذف القديم لو موجود
        if (File::exists($targetFile)) {
            File::delete($targetFile);
        }

        // رفع الجديد بنفس الاسم داخل resources/lang
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, ['json', 'txt'])) {
            throw new \Exception("Invalid file type: must be .json or .txt");
        }

        $file->move($langPath, $map[$field]);
    }
}

// ===================================================
// =============== Auth Helpers ======================
// ===================================================

if (!function_exists('userAuth')) {
    /**
     * Get authenticated user if role = user.
     */
    function userAuth()
    {
        $user = auth('api')->user();
        return ($user && strtolower($user->role ?? '') === 'user') ? $user : null;
    }
}

if (!function_exists('isAdmin')) {
    /**
     * Check if user is admin.
     */
    function isAdmin($user = null): bool
    {
        $user = $user ?? auth('api')->user();
        return $user && strtolower($user->role ?? '') === 'admin';
    }
}

// ===================================================
// =============== Language & Settings ===============
// ===================================================

if (!function_exists('getDefaultLanguage')) {
    /**
     * Get default language (cached).
     */
    function getDefaultLanguage($col_name = null)
    {
        $language = cache()->rememberForever(
            'default_language',
            fn() => Language::where('default', 'true')->first()
        );

        if (!$language) {
            throw new \Exception('Language not found.');
        }

        return $col_name ? $language->{$col_name} : $language;
    }
}

if (!function_exists('getAllLanguageWithoutDefault')) {
    /**
     * Get all languages except default (cached).
     */
    function getAllLanguageWithoutDefault()
    {
        return cache()->rememberForever(
            'languages_without_default',
            fn() => Language::where('default', 'false')->get()
        );
    }
}


if (!function_exists('getTranslationFile')) {
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

            if (!\Illuminate\Support\Facades\File::exists($jsonFile)) {
                return []; // empty array if file not exists
            }

            $content = \Illuminate\Support\Facades\File::get($jsonFile);
            return json_decode($content, true) ?? [];
        });
    }
}

if (!function_exists('clearLanguageCache')) {
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


if (!function_exists('rebuildLanguageCache')) {
    /**
     * Rebuild all language-related caches.
     */
    function rebuildLanguageCache(): void
    {
        clearCacheHelpers();

        Cache::rememberForever('all_languages', fn() => Language::all());
        Cache::rememberForever('available_locales', fn() => Language::pluck('code')->toArray());
        Cache::rememberForever('default_language', fn() => Language::where('default', 'true')->first());
        Cache::rememberForever('languages_without_default', fn() => Language::where('default', 'false')->get());
    }
}



if (!function_exists('getSettingValue')) {
    /**
     * Get setting value (cached).
     */
    function getSettingValue($key)
    {
        return cache()->rememberForever(
            "setting_{$key}",
            fn() => Setting::where('key', $key)->value('value')
        );
    }
}

// ===================================================
// =============== Utility Helpers ===================
// ===================================================

if (!function_exists('clearCacheHelpers')) {
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

if (!function_exists('formatFileSize')) {
    /**
     * Format bytes into KB, MB, GB.
     */
    function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }
}

if (!function_exists('humanDate')) {
    /**
     * Human-readable date (diffForHumans or formatted).
     */
    function humanDate($date, string $format = null): string
    {
        if (!$date) {
            return __('no_data_found');
        }

        $locale = getCurrentLocale();
        $carbonDate = Carbon::parse($date)->locale($locale);

        return $format ? $carbonDate->translatedFormat($format) : $carbonDate->diffForHumans();
    }
}

if (!function_exists('randomCode')) {
    /**
     * Generate random alphanumeric code.
     */
    function randomCode(int $length = 6): string
    {
        return strtoupper(substr(bin2hex(random_bytes($length)), 0, $length));
    }
}

if (!function_exists('moneyFormat')) {
    /**
     * Format money by locale & currency.
     */
    function moneyFormat($amount, string $currency = 'USD'): string
    {
        $locale = getCurrentLocale();
        $formatter = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
        return $formatter->formatCurrency($amount, $currency);
    }
}
