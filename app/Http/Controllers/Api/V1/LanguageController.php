<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Language;
use App\Services\languages\AppStrings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The language list the apps need on their very first screen, before any
 * account exists — so this stays public.
 */
class LanguageController extends Controller
{
    public function index(): JsonResponse
    {
        // `default` and `is_rtl` are enum('true','false') strings in this schema,
        // not booleans. Cast them here so clients get real JSON booleans.
        $languages = Language::getAllLanguages()->map(fn (Language $language) => [
            'code' => $language->code,
            'name' => $language->name,
            'name_en' => $language->name_en,
            'country_code' => $language->country_code,
            // getImageassetUrl() falls back to the bundled missing-image
            // placeholder, matching how the rest of the app resolves images.
            'icon' => getImageassetUrl($language->icon),
            'is_rtl' => $language->is_rtl === 'true',
            'is_default' => $language->default === 'true',
        ])->values();

        return successReturnData($languages);
    }

    /**
     * The translation set an app ships its own strings from.
     *
     * `app` is each app's own words, and the caller says which app it is —
     * `?app=user` (the customer app) or `?app=driver` — because the two bundle
     * different files with different keys (`AppStrings`). Nested, as the app
     * bundles it; a key the language has not translated comes back in English.
     *
     * `web` is the public site's Web File, flat, with no fallback: an empty
     * object when nothing has been uploaded.
     *
     * Public, because an app needs its own strings before anybody signs in.
     */
    public function translations(Request $request, string $type): JsonResponse
    {
        // A closed set. `panel` is deliberately absent: that file is the
        // dashboard's own 1,000-entry translation and no app has any use for it.
        if (! in_array($type, ['app', 'web'], true)) {
            return failReturnNotFound(__('That translation set does not exist.'));
        }

        $asked = $request->query('code');
        $code = strtolower(is_string($asked) && $asked !== '' ? $asked : app()->getLocale());

        if ($type === 'web') {
            return successReturnData([
                'code' => $code,
                'type' => $type,
                'strings' => (object) getTranslationFile($type, $code),
            ]);
        }

        $app = $request->query('app');

        if (! AppStrings::isApp($app)) {
            return failReturnValidation(
                ['app' => [__('Say which app is asking: app=user or app=driver.')]],
                __('Say which app is asking: app=user or app=driver.'),
            );
        }

        return successReturnData([
            'code' => $code,
            'type' => $type,
            'app' => $app,
            'strings' => (object) AppStrings::served($app, $code),
        ]);
    }
}
