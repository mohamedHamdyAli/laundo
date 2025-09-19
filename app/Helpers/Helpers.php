<?php

use App\Models\Language;
use App\Models\Setting;
use App\Models\Settings;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;

if (!function_exists('uploadOrUpdateImage')) {
    function uploadOrUpdateImage(?UploadedFile $image, string $directory, ?string $existingImagePath = null): ?string
    {
        if ($image) {
            if ($existingImagePath && Storage::disk('public')->exists($existingImagePath)) {
                Storage::disk('public')->delete($existingImagePath);
            }
            return $image->store($directory, 'public');
        }

        return $existingImagePath;
    }
}

if (!function_exists('DeleteImage')) {
    function DeleteImage(?string $existingImagePath): ?string
    {
        if ($existingImagePath) {
            // Delete the existing image if it exists

            if ($existingImagePath && Storage::disk('public')->exists($existingImagePath)) {
                Storage::disk('public')->delete($existingImagePath);
            }
        }

        // Return the existing image path if no new image was uploaded
        return true;
    }
}

if (!function_exists('getImageDashboardUrl')) {
    function getImageDashboardUrl($url)
    {
        if ($url && Storage::disk('public')->exists($url)) {
            $imageUrl = asset('storage/' . $url);
        } else {
            $imageUrl = asset('storage/default.png');
        }

        return "<a href='" . $imageUrl . "' target='_blank'>
                    <img class='rounded-circle' style='height: 80px; width: 80px; border-radius: 10%;' src='" . $imageUrl . "'>
                </a>";
    }
}

if (!function_exists('getImageassetUrl')) {
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

if (!function_exists('failedValidation')) {
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

if (!function_exists('getLocalizedValue')) {
    function getLocalizedValue($model, string $attribute): string
    {
        if (!$model || !$model->{$attribute}) {
            return __('no_data_found');
        }

        $locale = request()->header('lang') ?? app()->getLocale();
        return $model->{$attribute}->{$locale} ?? $model->{$attribute}->ar;
    }
}

if (!function_exists('userAuth')) {
    function userAuth()
    {
        $user = auth('api')->user();
        if ($user && strtolower($user->role ?? '') === 'user') {
            return $user;
        }
        return null;
    }
}

if (!function_exists('getDefaultLanguage')) {
    function getDefaultLanguage($col_name = null)
    {
        $language = Language::first();
        if (!$language) {
            throw new \Exception('Language not found.');
        }
        if ($col_name) {
            return $language->{$col_name};
        }
        return $language;
    }
}

if (!function_exists('getAllLanguageWithoutDefault')) {
    function getAllLanguageWithoutDefault()
    {
        $language = Language::where('id', '!=', '1')->get();
        if (!$language) {
            throw new \Exception('Language not found.');
        }
        return $language;
    }
}

if (!function_exists('getLocalizedValueDashboard')) {
    function getLocalizedValueDashboard($model, string $attribute): string
    {
        if (!$model || !$model->{$attribute}) {

            return 'No Data Found';
        }

        $locale = getDefaultLanguage();
        return $model->{$attribute}->{$locale} ?? $model->{$attribute}->ar;
    }
}


if (!function_exists('getSettingValue')) {

    function getSettingValue($key)
    {
        $setting = Setting::where('key', $key)->first();
        return $setting ? $setting->value : null;
    }
}
