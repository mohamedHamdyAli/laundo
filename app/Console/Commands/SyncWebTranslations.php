<?php

namespace App\Console\Commands;

use App\Models\Language;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Push new keys from the web translation template into every language.
 *
 * `LanguageHelper::generateJsonLanguageFiles()` already does this merge, but it
 * only runs when a language is **created**. So a key added to
 * `storage/app/webFile.php` afterwards reached no existing language, and
 * `webText()` would serve its English default to an Arabic visitor for ever
 * with nothing to show that anything was missing. That is the shape of bug
 * `tasks/lessons.md` opens with — a value added with no way to set it.
 *
 * ## Why this does not just call the existing helper
 *
 * `generateJsonLanguageFiles()` merges panel + mobile + web into
 * **`{code}.json`** as well as the three scoped files. `{code}.json` is the
 * panel's own hand-authored translation — 1,245 entries, all Arabic in `ar` —
 * and `TranslationCoverageTest::no_arabic_value_is_left_in_english` fails the
 * build on any value in it that contains no Arabic. Running the existing helper
 * over `ar` would tip a hundred English marketing strings into that file and
 * redden the suite.
 *
 * So this writes `{code}_web.json` and nothing else. It is the narrower tool,
 * and the narrowness is the point.
 *
 * Existing values always win. Adding a key adds a key; it can never overwrite a
 * translation somebody typed into the dashboard, which is the same guarantee
 * `replaceLanguageFile()` had to be given after it was found deleting a
 * thousand strings to add twenty.
 */
class SyncWebTranslations extends Command
{
    protected $signature = 'laundo:sync-web-lang
                            {--dry-run : List what would change and write nothing}
                            {--code= : Only this language code}';

    protected $description = 'Merge new keys from storage/app/webFile.php into each language\'s {code}_web.json';

    public function handle(): int
    {
        $template = webTemplateDefaults();

        if ($template === []) {
            $this->components->error('storage/app/webFile.php returned no keys.');

            return self::FAILURE;
        }

        $languages = Language::query()
            ->when($this->option('code'), fn ($query, $code) => $query->where('code', $code))
            ->get();

        if ($languages->isEmpty()) {
            $this->components->warn('No languages matched. Nothing to do.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $touched = 0;

        foreach ($languages as $language) {
            $path = lang_path("{$language->code}_web.json");

            $existing = [];
            if (File::exists($path)) {
                $decoded = json_decode(File::get($path), true);

                // A corrupt file is refused rather than silently replaced with
                // the template — overwriting it would destroy the translations
                // it still holds, which is exactly the accident this command is
                // written to be incapable of.
                if (! is_array($decoded)) {
                    $this->components->error("{$language->code}_web.json is not valid JSON. Skipped.");

                    continue;
                }

                $existing = $decoded;
            }

            $missing = array_diff_key($template, $existing);

            if ($missing === []) {
                $this->components->twoColumnDetail($language->code, '<fg=gray>up to date</>');

                continue;
            }

            $this->components->twoColumnDetail(
                $language->code,
                ($dryRun ? '<fg=yellow>would add</> ' : '<fg=green>added</> ').count($missing).' key(s)'
            );

            if ($this->output->isVerbose()) {
                foreach (array_keys($missing) as $key) {
                    $this->line("    <fg=gray>+</> {$key}");
                }
            }

            if ($dryRun) {
                $touched++;

                continue;
            }

            // Existing values win; ksort so the file reads in the same order the
            // dashboard's own editor writes it in.
            $merged = array_merge($template, $existing);
            ksort($merged);

            // A literal newline rather than PHP_EOL: `.gitattributes` pins
            // these files to LF, and PHP_EOL would have the command write CRLF
            // on Windows and LF elsewhere — a trailing-line diff that churns
            // every time a different machine runs it.
            File::put(
                $path,
                json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n"
            );

            // The reader caches for ever, so without this the new keys would not
            // appear until the cache expired — which, for rememberForever, is never.
            cache()->forget("lang_file_{$language->code}_web");

            $touched++;
        }

        if ($touched === 0) {
            $this->components->info('Every language already has every key.');
        } elseif ($dryRun) {
            $this->components->info("{$touched} language(s) would change. Re-run without --dry-run to write.");
        } else {
            $this->components->info("{$touched} language(s) updated.");
        }

        return self::SUCCESS;
    }
}
