<?php

namespace App\Helpers;

use Illuminate\Support\Facades\File;

class LanguageHelper
{
    /**
     * Where a language's file of a given type lives on disk.
     *
     * The one place this mapping exists. It used to be a `switch` inside
     * `downloadJson()` while the edit form worked out the same paths its own
     * way — through an *image* helper, which is how «View Current File» came to
     * open the brand placeholder.
     *
     * `panel` is `{code}.json`, not `{code}_panel.json`, and deliberately: the
     * panel editor screen (`showPanel`/`updatePanel`) reads and writes the main
     * file, so the download has to hand back the file that screen edits.
     * `{code}_panel.json` is generated too and is not what the panel reads.
     *
     * Returns null for a type nobody defines rather than guessing a filename.
     */
    public static function filePath(string $code, string $type): ?string
    {
        $name = match ($type) {
            'main', 'panel' => "{$code}.json",
            'mobile' => "{$code}_mobile.json",
            'web' => "{$code}_web.json",
            default => null,
        };

        return $name === null ? null : lang_path($name);
    }

    /**
     * The download type behind each of the three columns on the language form.
     *
     * The columns are named for the app they serve (`app_file`), the routes for
     * the file they fetch (`mobile`); this is the join.
     */
    public static function downloadTypeFor(string $column): ?string
    {
        return match ($column) {
            'app_file' => 'mobile',
            'panel_file' => 'panel',
            'web_file' => 'web',
            default => null,
        };
    }

    /**
     * Template defaults, with anything already translated left alone.
     *
     * @param  array<string, string>  $defaults
     * @return array<string, string>
     */
    private static function mergeWithExisting(string $path, array $defaults): array
    {
        if (! file_exists($path)) {
            return $defaults;
        }

        $existing = json_decode((string) file_get_contents($path), true);

        if (! is_array($existing)) {
            // A corrupt file is not a reason to lose the defaults too.
            return $defaults;
        }

        return array_merge($defaults, $existing);
    }

    private static function encode(array $translations): string
    {
        // JSON_UNESCAPED_UNICODE keeps Arabic readable in the file rather than
        // storing it as \\uXXXX, which nobody can review in a diff.
        return (string) json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    public static function generateJsonLanguageFiles($code)
    {
        $panelSource = storage_path('app/panelFile.php');
        $mobileSource = storage_path('app/mobileFile.php');
        $webSource = storage_path('app/webFile.php');

        if (! file_exists($panelSource)) {
            throw new \Exception("Translation source file not found at $panelSource");
        }
        if (! file_exists($mobileSource)) {
            throw new \Exception("Mobile translation source file not found at $mobileSource");
        }
        if (! file_exists($webSource)) {
            throw new \Exception("Web translation source file not found at $webSource");
        }

        $panelTranslations = include $panelSource;
        $mobileTranslations = include $mobileSource;
        $webTranslations = include $webSource;

        if (! is_array($panelTranslations)) {
            throw new \Exception('Panel translation file must return an array.');
        }
        if (! is_array($mobileTranslations)) {
            throw new \Exception('Mobile translation file must return an array.');
        }
        if (! is_array($webTranslations)) {
            throw new \Exception('Web translation file must return an array.');
        }

        $langDir = resource_path('lang');
        if (! is_dir($langDir)) {
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

        // Merged, never overwritten. The templates are a set of *default keys*,
        // not the whole truth: the panel's own translations live in
        // {code}.json and are hand-authored, and an admin may have edited a
        // scoped file through the dashboard. Clobbering a 734-entry file with a
        // ten-key template is how a day's translation work disappears when
        // somebody adds a language.
        //
        // Existing values win. A key the template introduces and the file lacks
        // is added; a key both have keeps what is already there.
        File::put($panelPath, self::encode(self::mergeWithExisting($panelPath, $panelTranslations)));
        File::put($mobilePath, self::encode(self::mergeWithExisting($mobilePath, $mobileTranslations)));
        File::put($webPath, self::encode(self::mergeWithExisting($webPath, $webTranslations)));
        File::put($defaultPath, self::encode(self::mergeWithExisting($defaultPath, $defaultTranslations)));

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
