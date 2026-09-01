<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Language;
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
            // getImageassetUrl() falls back to storage/default.png, matching how
            // the rest of the app resolves images.
            'icon' => getImageassetUrl($language->icon),
            'is_rtl' => $language->is_rtl === 'true',
            'is_default' => $language->default === 'true',
        ])->values();

        return successReturnData($languages);
    }

    /**
     * The translation set an app ships its own strings from.
     *
     * `replaceLanguageFile()` has always let an admin upload a mobile or web
     * translation file from the languages screen, and `getTranslationFile()` has
     * always been able to read one back — with **no caller anywhere**. So the
     * upload wrote a file nothing served: the same shape as a column with no form
     * field, from the other direction.
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

        $code = strtolower((string) ($request->get('code') ?: app()->getLocale()));

        return successReturnData([
            'code' => $code,
            'type' => $type,
            // An empty object when nothing has been uploaded — not an error. An
            // app with no overrides falls back to its own bundled strings.
            'strings' => (object) getTranslationFile($type, $code),
        ]);
    }
}
