<?php

namespace App\Services\languages;

use App\Helpers\LanguageHelper;
use App\Models\Language;
use App\Services\CachingService;
use App\Support\Translation\ValidationOverrideLoader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

class LanguageService
{
    /**
     * Make this language the only default.
     *
     * `default` is in `$fillable`, the form sends it and the request validates
     * it — and nothing ever demoted the previous holder, so marking a second
     * language default left **two** rows saying `'true'`. `getDefaultLanguage()`
     * is a `where(...)->first()`, so which of them won was decided by id order:
     * the panel would keep speaking the old language and look like the setting
     * had been ignored.
     *
     * Runs inside the caller's transaction, so a failure cannot leave the
     * platform with no default at all.
     */
    private function makeSoleDefault(Language $language): void
    {
        Language::where('id', '!=', $language->id)->update(['default' => 'false']);
    }

    /**
     * Refuse to leave the platform with no default language.
     *
     * `getDefaultLanguage()` throws when no row is flagged, and it is called
     * from the locale helpers on every request — so un-defaulting the last one
     * does not degrade the panel, it takes it down. Surfaced as a validation
     * error on the field that caused it rather than a 500.
     */
    private function guardLastDefault(Language $language, ?string $incoming): void
    {
        if ($incoming !== 'false' || (string) $language->default !== 'true') {
            return;
        }

        if (Language::where('default', 'true')->where('id', '!=', $language->id)->exists()) {
            return;
        }

        throw ValidationException::withMessages([
            'default' => __('This is the only default language. Make another language the default first.'),
        ]);
    }

    public function createLanguage(array $data): Language
    {
        // Generate default JSON files and merge paths returned by helper
        $files = LanguageHelper::generateJsonLanguageFiles($data['code']);
        $data = array_merge($data, $files);

        // upload icon if exists
        $data['icon'] = uploadOrUpdateImage($data['icon'] ?? null, 'images/lang/icon');

        $language = DB::transaction(function () use ($data) {
            $language = Language::create($data);

            if ((string) ($data['default'] ?? 'false') === 'true') {
                $this->makeSoleDefault($language);
            }

            return $language;
        });

        // Clear language caches for fresh reads
        clearLanguageCache($data['code']);
        rebuildLanguageCache();
        CachingService::forgetLanguages();

        return $language;
    }

    public function getLanguages()
    {
        // will hit Language::getAllLanguages() which is cached
        return Language::getAllLanguages();
    }

    public function updateRecord($request)
    {
        $filteredRequest = array_filter($request, fn ($value) => ! is_null($value));
        $previousCode = null;

        $language = DB::transaction(function () use ($filteredRequest, &$previousCode) {
            $existingLanguage = Language::findOrFail($filteredRequest['id']);
            $previousCode = (string) $existingLanguage->code;

            // Before the write: the guard reads the row's current state, and
            // after `update()` that state is gone.
            $this->guardLastDefault($existingLanguage, $filteredRequest['default'] ?? null);

            if (isset($filteredRequest['icon'])) {
                $existingPath = $existingLanguage->icon;
                $filteredRequest['icon'] = uploadOrUpdateImage(
                    $filteredRequest['icon'],
                    'images/lang/icon',
                    $existingPath
                );
            }

            $fileFields = ['panel_file', 'web_file'];
            foreach ($fileFields as $field) {
                if (request()->hasFile($field)) {
                    replaceLanguageFile($existingLanguage, $field, request()->file($field));
                }
            }

            $existingLanguage->update($filteredRequest);

            if ((string) ($filteredRequest['default'] ?? 'false') === 'true') {
                $this->makeSoleDefault($existingLanguage);
            }

            return $existingLanguage;
        });

        $this->moveValidationMessages($previousCode, (string) $language->code);
        AppStrings::moveOverrides($previousCode, (string) $language->code);

        clearLanguageCache($language->code);
        rebuildLanguageCache();
        // The topbar switcher reads a *different* cache with a 1-hour TTL, and
        // nothing here used to clear it — so the list of languages and their
        // flags stayed stale for up to an hour after an edit. CLAUDE.md has
        // carried that as a known rough edge; this is it closed.
        CachingService::forgetLanguages();

        return $language;
    }

    public function shredData($id = null)
    {
        $data = [];
        if ($id != null) {
            $data['row'] = Language::findOrFail($id);
        }

        return $data;
    }

    /**
     * A language whose code changed keeps the validation messages edited for
     * it. The file is named by the code, so left where it was they would stop
     * answering — and would answer instead for the next language given the old
     * code. A file already under the new code is not overwritten.
     */
    private function moveValidationMessages(?string $from, string $to): void
    {
        if ($from === null || $from === $to
            || ! ValidationOverrideLoader::isLocale($from) || ! ValidationOverrideLoader::isLocale($to)) {
            return;
        }

        $source = ValidationOverrideLoader::pathFor($from);
        $target = ValidationOverrideLoader::pathFor($to);

        if (is_file($source) && ! is_file($target)) {
            File::move($source, $target);
        }
    }

    public function deleteRecord($id)
    {
        $languageCode = null;

        DB::transaction(function () use ($id, &$languageCode) {
            $language = Language::findOrFail($id);

            if ($language->default === 'true') {
                throw new \Exception('Cannot delete the default language.');
            }

            $languageCode = $language->code;

            $files = [
                base_path("resources/lang/{$languageCode}_panel.json"),
                base_path("resources/lang/{$languageCode}_web.json"),
                base_path("resources/lang/{$languageCode}.json"),
            ];

            // Its edited validation messages, which live with the rest of the
            // runtime data rather than beside the shipped files.
            if (ValidationOverrideLoader::isLocale((string) $languageCode)) {
                $files[] = ValidationOverrideLoader::pathFor($languageCode);
            }

            // What an operator typed for each app. The shipped files are the
            // app teams' and stay.
            foreach (AppStrings::APPS as $app) {
                $files[] = AppStrings::overridesPath($app, (string) $languageCode);
            }

            foreach (array_filter($files) as $file) {
                if (File::exists($file)) {
                    File::delete($file);
                }
            }

            $language->delete();
        });

        // clear caches for this language
        if ($languageCode) {
            clearLanguageCache($languageCode);
            rebuildLanguageCache();
        }

        return true;
    }
}
