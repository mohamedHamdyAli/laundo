<?php

namespace App\Services\languages;

use App\Helpers\LanguageHelper;
use App\Models\Language;
use App\Services\CachingService;
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

        $language = DB::transaction(function () use ($filteredRequest) {
            $existingLanguage = Language::findOrFail($filteredRequest['id']);

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

            $fileFields = ['panel_file', 'app_file', 'web_file'];
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

    public function getLanguageData($id = null, $type = null)
    {
        return DB::transaction(function () use ($id, $type) {
            $data = [];
            if ($id != null) {
                $language = Language::findOrFail($id);
                $data['row'] = $language;

                if ($type != null) {
                    $languageCode = $language->code ?? 'en';

                    // determine fileName & defaultFile
                    switch ($type) {
                        case 'panel':
                            $fileName = $language->panel_file ?: "{$languageCode}_panel.json";
                            $defaultFile = base_path('resources/lang/en_panel.json');
                            break;
                        case 'app':
                            $fileName = $language->app_file ?: "{$languageCode}_mobile.json";
                            $defaultFile = base_path('resources/lang/en_mobile.json');
                            break;
                        case 'web':
                            $fileName = $language->web_file ?: "{$languageCode}_web.json";
                            $defaultFile = base_path('resources/lang/en_web.json');
                            break;
                        default:
                            $fileName = "{$languageCode}.json";
                            $defaultFile = base_path('resources/lang/en.json');
                            break;
                    }

                    $fileName = basename($fileName);
                    $jsonFile = base_path("resources/lang/{$fileName}");

                    // ensure file exists - if not, copy default
                    if (! File::exists($jsonFile)) {
                        $defaultContent = (File::exists($defaultFile)) ? File::get($defaultFile) : json_encode([]);
                        File::put($jsonFile, $defaultContent);

                        // save filename to language row if missing
                        switch ($type) {
                            case 'panel':
                                $language->panel_file = $fileName;
                                break;
                            case 'app':
                                $language->app_file = $fileName;
                                break;
                            case 'web':
                                $language->web_file = $fileName;
                                break;
                        }
                        $language->save();

                        // clear language cache (we changed model)
                        clearLanguageCache($language->code);
                        rebuildLanguageCache();
                    }

                    // Read default and target content
                    $enContent = File::exists($defaultFile) ? json_decode(File::get($defaultFile), true) : [];
                    $targetContent = File::exists($jsonFile) ? json_decode(File::get($jsonFile), true) : [];

                    // merge missing keys from default EN content
                    foreach ($enContent as $key => $value) {
                        if (! array_key_exists($key, $targetContent)) {
                            $targetContent[$key] = $value;
                        }
                    }

                    // Save merged content back to file (pretty)
                    File::put($jsonFile, json_encode($targetContent, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                    // Update cache for this translation file
                    $cacheKey = "lang_file_{$languageCode}_{$type}";
                    cache()->put($cacheKey, $targetContent);

                    // set response content (use updated content)
                    $data['enLabels'] = $targetContent;
                    $data['type'] = $type;
                }
            }

            return $data;
        });
    }

    public function updatelanguage($id, $type, $updatedLabels)
    {
        return DB::transaction(function () use ($id, $type, $updatedLabels) {
            $language = Language::findOrFail($id);

            $jsonFile = match ($type) {
                'panel' => base_path('resources/lang/'.basename((string) $language->panel_file)),
                'app' => base_path('resources/lang/'.basename((string) $language->app_file)),
                'web' => base_path('resources/lang/'.basename($language->web_file ?? '')),
                default => base_path('resources/lang/'.($language->code.'.json')),
            };

            $directory = dirname($jsonFile);
            if (! File::exists($directory)) {
                File::makeDirectory($directory, 0755, true);
            }

            if (! File::exists($jsonFile)) {
                File::put($jsonFile, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }

            $jsonContent = File::get($jsonFile);
            $enLabels = json_decode($jsonContent, true) ?? [];

            // update labels
            foreach ($updatedLabels as $key => $value) {
                $enLabels[$key] = $value;
            }

            // save back to file
            File::put($jsonFile, json_encode($enLabels, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            // refresh cache for this language file
            $code = $language->code ?? 'en';
            $cacheKey = "lang_file_{$code}_{$type}";
            cache()->put($cacheKey, $enLabels);

            return true;
        });
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
                base_path("resources/lang/{$languageCode}_mobile.json"),
                base_path("resources/lang/{$languageCode}_web.json"),
                base_path("resources/lang/{$languageCode}.json"),
            ];

            foreach ($files as $file) {
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
