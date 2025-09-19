<?php

namespace App\Helpers;

use Illuminate\Support\Facades\File;

class LanguageHelper
{

    public static function generateJsonLanguageFiles($code)
    {
        $panelSource = storage_path('app/panelFile.php');
        $mobileSource = storage_path('app/mobileFile.php');


        if (!file_exists($panelSource)) {
            throw new \Exception("Translation source file not found at $panelSource");
        }
        if (!file_exists($mobileSource)) {
            throw new \Exception("Mobile translation source file not found at $mobileSource");
        }

        $panelTranslations = include $panelSource;
        $mobileTranslations = include $mobileSource;


        if (!is_array($panelTranslations)) {
            throw new \Exception("Panel translation file must return an array.");
        }
        if (!is_array($mobileTranslations)) {
            throw new \Exception("Mobile translation file must return an array.");
        }


        $langDir = resource_path('lang');
        if (!is_dir($langDir)) {
            mkdir($langDir, 0755, true);
        }

        $panelFileName = "{$code}_panel.json";
        $mobileFileName = "{$code}_mobile.json";
        $defaultFileName = "{$code}.json";

        $panelPath = "$langDir/$panelFileName";
        $mobilePath = "$langDir/$mobileFileName";
        $defaultPath = "$langDir/$defaultFileName";

        $defaultTranslations = array_merge($panelTranslations, $mobileTranslations);

        File::put($panelPath, json_encode($panelTranslations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        File::put($mobilePath, json_encode($mobileTranslations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        File::put($defaultPath, json_encode($defaultTranslations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));


        return [
            'app_file' => "lang/{$mobileFileName}",
            'panel_file' => "lang/{$panelFileName}",
            'default_file' => "lang/{$defaultFileName}",
        ];
    }
}
