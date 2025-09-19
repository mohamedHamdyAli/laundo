<?php

namespace App\Services\languages;

use App\Models\Language;
use App\Helpers\LanguageHelper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class LanguageService
{
    public function createLanguage(array $data): Language
    {
        // Generate default JSON files and merge paths returned by helper
        $files = LanguageHelper::generateJsonLanguageFiles($data['code']);
        $data = array_merge($data, $files);

        // upload icon if exists
        $data['icon'] = uploadOrUpdateImage($data['icon'] ?? null, 'images/lang/icon');

        $language = Language::create($data);

        // Clear language caches for fresh reads
        clearLanguageCache($data['code']);
        rebuildLanguageCache();


        return $language;
    }

    public function getLanguages()
    {
        // will hit Language::getAllLanguages() which is cached
        return Language::getAllLanguages();
    }

    public function updateRecord($request)
    {
        $filteredRequest = array_filter($request, function ($value) {
            return !is_null($value);
        });

        $language = DB::transaction(function () use ($filteredRequest) {
            $existingLanguage = Language::findOrFail($filteredRequest['id']);

            if (isset($filteredRequest['icon'])) {
                $existingPath = $existingLanguage?->icon;
                $filteredRequest['icon'] = uploadOrUpdateImage($filteredRequest['icon'], 'images/lang/icon', $existingPath);
            }

            $existingLanguage->update($filteredRequest);
            return $existingLanguage;
        });

        // clear cache related to this language
        clearLanguageCache($language->code);
        rebuildLanguageCache();


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
                            $fileName = $language->app_file ?: "{$languageCode}_web.json";
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
                    if (!File::exists($jsonFile)) {
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
                        if (!array_key_exists($key, $targetContent)) {
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
                'panel' => base_path("resources/lang/" . basename($language->panel_file)),
                'app' => base_path("resources/lang/" . basename($language->app_file)),
                'web' => base_path("resources/lang/" . basename($language->web_file ?? '')),
                default => base_path('resources/lang/' . ($language->code . '.json')),
            };

            $directory = dirname($jsonFile);
            if (!File::exists($directory)) {
                File::makeDirectory($directory, 0755, true);
            }

            if (!File::exists($jsonFile)) {
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
                throw new \Exception("Cannot delete the default language.");
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
