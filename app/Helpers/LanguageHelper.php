<?php

namespace App\Helpers;

use Illuminate\Support\Facades\File;

class LanguageHelper
{

    public static function generateJsonLanguageFiles($code)
    {
        $panelSource = storage_path('app/panelFile.php');
        $mobileSource = storage_path('app/mobileFile.php');
        $webSource = storage_path('app/webFile.php');

        if (!file_exists($panelSource)) {
            throw new \Exception("Translation source file not found at $panelSource");
        }
        if (!file_exists($mobileSource)) {
            throw new \Exception("Mobile translation source file not found at $mobileSource");
        }
        if (!file_exists($webSource)) {
            throw new \Exception("Web translation source file not found at $webSource");
        }

        $panelTranslations = include $panelSource;
        $mobileTranslations = include $mobileSource;
        $webTranslations = include $webSource;

        if (!is_array($panelTranslations)) {
            throw new \Exception("Panel translation file must return an array.");
        }
        if (!is_array($mobileTranslations)) {
            throw new \Exception("Mobile translation file must return an array.");
        }
        if (!is_array($webTranslations)) {
            throw new \Exception("Web translation file must return an array.");
        }

        $langDir = resource_path('lang');
        if (!is_dir($langDir)) {
            mkdir($langDir, 0777, true);
        }

        $panelFileName = "{$code}_panel.json";
        $mobileFileName = "{$code}_mobile.json";
        $webFileName = "{$code}_web.json";
        $defaultFileName = "{$code}.json";

        $panelPath = "$langDir/$panelFileName";
        $mobilePath = "$langDir/$mobileFileName";
        $webPath = "$langDir/$webFileName";
        $defaultPath = "$langDir/$defaultFileName";

        $defaultTranslations = array_merge($panelTranslations, $mobileTranslations, $webTranslations);

        // save files
        File::put($panelPath, json_encode($panelTranslations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        File::put($mobilePath, json_encode($mobileTranslations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        File::put($webPath, json_encode($webTranslations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        File::put($defaultPath, json_encode($defaultTranslations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // 🔑 clear/update cache for this language
        clearLanguageCache($code);

        cache()->put("lang_file_{$code}_panel", $panelTranslations);
        cache()->put("lang_file_{$code}_app", $mobileTranslations);
        cache()->put("lang_file_{$code}_web", $webTranslations);
        cache()->put("lang_file_{$code}_default", $defaultTranslations);

        return [
            'app_file' => "lang/{$mobileFileName}",
            'panel_file' => "lang/{$panelFileName}",
            'web_file' => "lang/{$webFileName}",
            'default_file' => "lang/{$defaultFileName}",
        ];
    }

}
