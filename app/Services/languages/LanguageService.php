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

        $files = LanguageHelper::generateJsonLanguageFiles($data['code']);
        $data = array_merge($data, $files);
        $data['icon'] = uploadOrUpdateImage($data['icon'] ?? null, 'images/lang/icon');

        return Language::create($data);
    }

    public function getLanguages()
    {
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

                    switch ($type) {
                        case 'panel':
                            $fileName = $language->panel_file ?: "{$languageCode}_panel.json";
                            $fileName = basename($fileName);
                            $defaultFile = base_path('resources/lang/en_panel.json');
                            break;

                        case 'app':
                            $fileName = $language->app_file ?: "{$languageCode}_mobile.json";
                            $fileName = basename($fileName);
                            $defaultFile = base_path('resources/lang/en_mobile.json');
                            break;

                        default:
                            $fileName = 'en.json';
                            $defaultFile = base_path('resources/lang/en.json');
                            break;
                    }


                    $jsonFile = base_path("resources/lang/{$fileName}");

                    if (!File::exists($jsonFile)) {
                        $defaultContent = (File::exists($defaultFile)) ? File::get($defaultFile) : json_encode([]);

                        File::put($jsonFile, $defaultContent);

                        switch ($type) {
                            case 'panel':
                                $language->panel_file = $fileName;
                                break;
                            case 'app':
                                $language->app_file = $fileName;
                                break;
                        }
                        $language->save();
                    }

                    $jsonContent = File::get($jsonFile);

                    $enContent = File::exists($defaultFile) ? json_decode(File::get($defaultFile), true) : [];
                    $targetContent = File::exists($jsonFile) ? json_decode(File::get($jsonFile), true) : [];

                    foreach ($enContent as $key => $value) {
                        if (!array_key_exists($key, $targetContent)) {
                            $targetContent[$key] = $value;
                        }
                    }
                    File::put($jsonFile, json_encode($targetContent, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    $enLabels = json_decode($jsonContent, true);

                    $data['enLabels'] = $enLabels;
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

                default => base_path('resources/lang/ar.json'),
            };

            $directory = dirname($jsonFile);
            if (!File::exists($directory)) {
                File::makeDirectory($directory, 0755, true);
            }

            if (!File::exists($jsonFile)) {
                File::put($jsonFile, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }

            $jsonContent = File::get($jsonFile);
            $enLabels = json_decode($jsonContent, true);

            foreach ($updatedLabels as $key => $value) {
                $enLabels[$key] = $value;
            }


            File::put($jsonFile, json_encode($enLabels, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return true;
        });
    }



    public function deleteRecord($id)
    {
        DB::transaction(function () use ($id) {
            $language = Language::findOrFail($id);
            $language->delete();
        });

        return true;
    }
}
