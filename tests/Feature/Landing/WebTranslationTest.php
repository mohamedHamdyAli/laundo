<?php

namespace Tests\Feature\Landing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Web File — the mechanism the landing page's copy lives in.
 *
 * Two things are being protected here.
 *
 * The first is the **fallback chain**. `webText()` walks locale ->
 * default language -> shipped template -> the key. Every rung matters: without
 * the third, adding a key to `webFile.php` renders `landing.hero.title` to a
 * visitor until somebody runs a command, and without the second a key
 * translated in Arabic only shows nothing at all on the English page.
 *
 * The second is that `laundo:sync-web-lang` **cannot touch `{code}.json`**.
 * `LanguageHelper::generateJsonLanguageFiles()` merges the web template into
 * that file as well as the scoped one, and `{code}.json` is the panel's own
 * hand-authored translation — 1,245 entries, every value Arabic in `ar`, with
 * `TranslationCoverageTest` failing the build on any value that holds no
 * Arabic. Running the general helper over `ar` would tip a hundred English
 * marketing strings in there and redden the suite. This is the test that says
 * so out loud.
 */
class WebTranslationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->seedCore();
    }

    /** @return array<string, string> */
    private function template(): array
    {
        /** @var array<string, string> $template */
        $template = require storage_path('app/webFile.php');

        return $template;
    }

    /** @return array<string, string> */
    private function webFile(string $code): array
    {
        $path = lang_path("{$code}_web.json");

        $this->assertFileExists($path, "{$code}_web.json is missing");

        /** @var array<string, string> $decoded */
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    // ---------------------------------------------------------------------
    // Coverage
    // ---------------------------------------------------------------------

    #[Test]
    public function every_template_key_exists_in_both_shipped_languages(): void
    {
        $template = $this->template();

        $this->assertNotEmpty($template);

        foreach (['en', 'ar'] as $code) {
            $missing = array_keys(array_diff_key($template, $this->webFile($code)));

            $this->assertSame(
                [],
                $missing,
                "{$code}_web.json is missing:\n  ".implode("\n  ", $missing)
                ."\nRun: php artisan laundo:sync-web-lang"
            );
        }
    }

    #[Test]
    public function every_arabic_landing_value_actually_holds_arabic(): void
    {
        // The same check `TranslationCoverageTest` applies to ar.json, for the
        // file that one cannot see. A value copied across untranslated is
        // invisible: the key is English and so is the value, and nothing looks
        // wrong until a customer reads it.
        $untranslated = [];

        foreach ($this->webFile('ar') as $key => $value) {
            if (! str_starts_with($key, 'landing.')) {
                continue;
            }

            if (trim((string) $value) === '') {
                $untranslated[] = "{$key} => (empty)";

                continue;
            }

            if (preg_match('/[\x{0600}-\x{06FF}]/u', (string) $value) !== 1) {
                $untranslated[] = "{$key} => '{$value}'";
            }
        }

        $this->assertSame(
            [],
            $untranslated,
            "ar_web.json landing entries holding no Arabic:\n  ".implode("\n  ", $untranslated)
        );
    }

    #[Test]
    public function no_translation_drops_a_placeholder(): void
    {
        // A sentence that loses `:count` renders with a hole in it, and the hole
        // is where the number of areas was supposed to be.
        $template = $this->template();
        $broken = [];

        foreach (['en', 'ar'] as $code) {
            foreach ($this->webFile($code) as $key => $value) {
                if (! isset($template[$key])) {
                    continue;
                }

                preg_match_all('/:[a-zA-Z_]+/', (string) $template[$key], $wanted);
                preg_match_all('/:[a-zA-Z_]+/', (string) $value, $got);

                $lost = array_diff($wanted[0], $got[0]);

                if ($lost !== []) {
                    $broken[] = "{$code}: {$key} lost ".implode(', ', $lost);
                }
            }
        }

        $this->assertSame([], $broken, implode("\n  ", $broken));
    }

    // ---------------------------------------------------------------------
    // The fallback chain
    // ---------------------------------------------------------------------

    #[Test]
    public function it_reads_the_current_locales_file_first(): void
    {
        app()->setLocale('ar');

        $this->assertSame($this->webFile('ar')['landing.hero.title'], webText('landing.hero.title'));
    }

    #[Test]
    public function it_falls_back_to_the_default_language(): void
    {
        // A key present in English and absent from Arabic must render the
        // English rather than nothing — the same rule `pickTranslation()`
        // applies to translatable columns, and for the same reason.
        $path = lang_path('ar_web.json');
        $original = File::get($path);

        try {
            $stripped = $this->webFile('ar');
            unset($stripped['landing.hero.title']);
            File::put($path, json_encode($stripped, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            Cache::flush();

            app()->setLocale('ar');

            $this->assertSame(
                $this->webFile('en')['landing.hero.title'],
                webText('landing.hero.title')
            );
        } finally {
            // In a finally, so a failing assertion cannot leave the repository's
            // Arabic translation file truncated.
            File::put($path, $original);
            Cache::flush();
        }
    }

    #[Test]
    public function it_falls_back_to_the_shipped_template(): void
    {
        // The rung that makes adding a key safe: it renders its English default
        // everywhere the moment it exists, with no command to run first.
        $key = 'landing.hero.title';
        $paths = [lang_path('en_web.json'), lang_path('ar_web.json')];
        $originals = array_map(fn (string $p) => File::get($p), $paths);

        try {
            foreach ($paths as $path) {
                $decoded = json_decode(File::get($path), true);
                unset($decoded[$key]);
                File::put($path, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
            Cache::flush();

            $this->assertSame($this->template()[$key], webText($key));
        } finally {
            foreach ($paths as $i => $path) {
                File::put($path, $originals[$i]);
            }
            Cache::flush();
        }
    }

    #[Test]
    public function an_unknown_key_returns_itself_rather_than_an_empty_string(): void
    {
        // An empty string is a blank space nobody notices; the key is a visible
        // signal that something needs writing.
        $this->assertSame('landing.nope.nothing', webText('landing.nope.nothing'));
        $this->assertSame('a default', webText('landing.nope.nothing', [], 'a default'));
    }

    #[Test]
    public function it_replaces_placeholders_in_every_casing(): void
    {
        $this->assertSame(
            '4 laundry services',
            webText('landing.facts.services', ['count' => 4])
        );
    }

    // ---------------------------------------------------------------------
    // The sync command
    // ---------------------------------------------------------------------

    #[Test]
    public function the_sync_command_never_writes_the_panel_translation_file(): void
    {
        $guarded = [
            lang_path('en.json'),
            lang_path('ar.json'),
            lang_path('en_panel.json'),
            lang_path('ar_panel.json'),
            lang_path('en_mobile.json'),
            lang_path('ar_mobile.json'),
        ];

        $before = [];
        foreach ($guarded as $path) {
            $before[$path] = File::exists($path) ? md5_file($path) : null;
        }

        $this->artisan('laundo:sync-web-lang')->assertSuccessful();

        foreach ($guarded as $path) {
            $after = File::exists($path) ? md5_file($path) : null;

            $this->assertSame(
                $before[$path],
                $after,
                basename($path).' was modified. Only {code}_web.json may be written — '
                .'English marketing copy in ar.json fails TranslationCoverageTest.'
            );
        }
    }

    #[Test]
    public function the_sync_command_adds_missing_keys_and_keeps_existing_values(): void
    {
        $path = lang_path('ar_web.json');
        $original = File::get($path);

        try {
            $edited = $this->webFile('ar');
            $edited['landing.hero.title'] = 'عنوان عدّله المشرف';
            unset($edited['landing.hero.lead']);
            File::put($path, json_encode($edited, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            Cache::flush();

            $this->artisan('laundo:sync-web-lang --code=ar')->assertSuccessful();

            $after = $this->webFile('ar');

            // The edit survives — this is the guarantee `replaceLanguageFile()`
            // had to be given after it was found deleting a thousand strings to
            // add twenty.
            $this->assertSame('عنوان عدّله المشرف', $after['landing.hero.title']);

            // And the removed key comes back from the template.
            $this->assertArrayHasKey('landing.hero.lead', $after);
            $this->assertSame($this->template()['landing.hero.lead'], $after['landing.hero.lead']);
        } finally {
            File::put($path, $original);
            Cache::flush();
        }
    }

    #[Test]
    public function the_sync_command_is_idempotent(): void
    {
        $before = md5_file(lang_path('ar_web.json'));

        $this->artisan('laundo:sync-web-lang')->assertSuccessful();

        $this->assertSame($before, md5_file(lang_path('ar_web.json')));
    }

    #[Test]
    public function a_dry_run_writes_nothing(): void
    {
        $path = lang_path('ar_web.json');
        $original = File::get($path);

        try {
            $stripped = $this->webFile('ar');
            unset($stripped['landing.hero.title']);
            File::put($path, json_encode($stripped, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $hash = md5_file($path);

            $this->artisan('laundo:sync-web-lang --dry-run')->assertSuccessful();

            $this->assertSame($hash, md5_file($path));
        } finally {
            File::put($path, $original);
            Cache::flush();
        }
    }

    #[Test]
    public function the_web_file_is_still_served_to_the_apps(): void
    {
        // The landing page is the second consumer of this mechanism, not a
        // replacement for the first: `GET /api/v1/translations/web` has served
        // it since the endpoint was added, and the apps read it.
        $response = $this->getJson('/api/v1/translations/web?code=ar')
            ->assertOk()
            ->assertJsonPath('data.type', 'web')
            ->assertJsonPath('data.code', 'ar');

        // Read out of the decoded body rather than through `assertJsonPath`:
        // these keys contain dots, and `data_get()` splits on them, so a path
        // expression cannot address `landing.hero.title` at all.
        $strings = $response->json('data.strings');

        $this->assertIsArray($strings);
        $this->assertSame(
            $this->webFile('ar')['landing.hero.title'],
            $strings['landing.hero.title'] ?? null
        );
    }
}
