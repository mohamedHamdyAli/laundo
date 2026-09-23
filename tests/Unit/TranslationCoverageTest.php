<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Arabic coverage for the strings a code scan cannot see.
 *
 * Translation gaps in this project have been found twice by extracting every
 * `__()` call and diffing it against `resources/lang/ar.json`. That scan has a
 * blind spot: a string that lives in a config array as plain text and only meets
 * `__()` at render time. `config/menu.php` is full of them, and five sidebar
 * items — Banners, Intros, Countries, Cities, Roles — sat in English inside an
 * otherwise fully Arabic menu because of it.
 *
 * A test rather than a one-off fix, because the same gap reopens the moment
 * somebody adds a module: the checklist in CLAUDE.md says to add a `titles` entry
 * and says nothing about translating it.
 */
class TranslationCoverageTest extends TestCase
{
    /** @return array<string, string> */
    private function arabic(): array
    {
        $path = dirname(__DIR__, 2).'/resources/lang/ar.json';

        $this->assertFileExists($path, 'the Arabic translation file is missing');

        /** @var array<string, string> $decoded */
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /** @return array{groups: array<string, array{title?: string}>, titles: array<string, string>} */
    private function menu(): array
    {
        /** @var array{groups: array<string, array{title?: string}>, titles: array<string, string>} $menu */
        $menu = require dirname(__DIR__, 2).'/config/menu.php';

        return $menu;
    }

    #[Test]
    public function every_sidebar_item_title_has_an_arabic_translation(): void
    {
        $arabic = $this->arabic();
        $menu = $this->menu();

        $this->assertNotEmpty($menu['titles'], 'config/menu.php has no titles to check');

        $missing = [];

        foreach ($menu['titles'] as $key => $title) {
            if (! isset($arabic[$title])) {
                $missing[] = "{$key} => '{$title}'";
            }
        }

        $this->assertSame(
            [],
            $missing,
            "config/menu.php titles with no entry in ar.json:\n  ".implode("\n  ", $missing)
        );
    }

    #[Test]
    public function every_sidebar_group_heading_has_an_arabic_translation(): void
    {
        $arabic = $this->arabic();
        $missing = [];

        foreach ($this->menu()['groups'] as $key => $group) {
            $title = $group['title'] ?? null;

            if ($title !== null && ! isset($arabic[$title])) {
                $missing[] = "{$key} => '{$title}'";
            }
        }

        $this->assertSame(
            [],
            $missing,
            "config/menu.php group headings with no entry in ar.json:\n  ".implode("\n  ", $missing)
        );
    }

    #[Test]
    public function every_sidebar_item_has_an_icon_and_a_route_as_well_as_a_title(): void
    {
        // Not a translation concern, but the same shape of bug and nothing else
        // checks it: MenuBuilder reads three parallel maps, and an entry present
        // in one and absent from another renders as a null — a blank sidebar row
        // that looks like a permission problem.
        $menu = $this->menu();
        /** @var array{icons: array<string, string>, routes: array<string, string>, titles: array<string, string>} $menu */
        $problems = [];

        foreach (array_keys($menu['titles']) as $key) {
            foreach (['icons', 'routes'] as $map) {
                if (! isset($menu[$map][$key])) {
                    $problems[] = "{$key} has a title but no {$map} entry";
                }
            }
        }

        // And the reverse: an icon for something with no title is dead config.
        foreach (array_keys($menu['icons']) as $key) {
            if (! isset($menu['titles'][$key])) {
                $problems[] = "{$key} has an icon but no title";
            }
        }

        $this->assertSame([], $problems, implode("\n  ", $problems));
    }

    #[Test]
    public function no_arabic_value_is_left_in_english(): void
    {
        // A value copied across untranslated is invisible: the key is English and
        // so is the value, so nothing looks wrong until a customer reads it.
        $untranslated = [];

        foreach ($this->arabic() as $key => $value) {
            if (trim($value) === '') {
                $untranslated[] = "{$key} => (empty)";

                continue;
            }

            if (preg_match('/[\x{0600}-\x{06FF}]/u', $value) !== 1) {
                $untranslated[] = "{$key} => '{$value}'";
            }
        }

        $this->assertSame(
            [],
            $untranslated,
            'ar.json entries holding no Arabic:\n  '.implode("\n  ", $untranslated)
        );
    }

    #[Test]
    public function no_translation_drops_a_placeholder(): void
    {
        // A message that loses :code renders as a sentence with a hole in it, and
        // the hole is where the order number was supposed to be.
        $broken = [];

        foreach ($this->arabic() as $key => $value) {
            preg_match_all('/:[a-zA-Z_]+/', $key, $wanted);
            preg_match_all('/:[a-zA-Z_]+/', $value, $got);

            $lost = array_diff($wanted[0], $got[0]);

            if ($lost !== []) {
                $broken[] = "{$key} lost ".implode(', ', $lost);
            }
        }

        $this->assertSame([], $broken, implode("\n  ", $broken));
    }

    /**
     * Every translatable string written in the code has Arabic.
     *
     * The scan this class's own docblock describes — extracting each `__()` and
     * diffing it against `ar.json` — done here instead of by hand. It had been
     * run twice and thrown away twice, and both times the gap had already
     * reached a screen: most recently the whole header of the price grid, which
     * is the sentence explaining the figure beneath it, rendering in English on
     * an Arabic page.
     *
     * Only literals are checked. `__($status->label())` passes a variable and
     * cannot be seen from here, which is what the config-array tests above are
     * for — between them the two halves cover what either alone would miss.
     */
    #[Test]
    public function every_translatable_string_in_the_code_has_arabic(): void
    {
        $arabic = $this->arabic();
        $missing = [];

        foreach ($this->sourceFiles() as $file) {
            $source = (string) file_get_contents($file);

            foreach ($this->translatableLiterals($source) as $key) {
                if ($key === '' || isset($arabic[$key])) {
                    continue;
                }

                $missing[] = basename($file).': '.$key;
            }
        }

        $missing = array_values(array_unique($missing));
        sort($missing);

        $this->assertSame([], $missing, implode("\n  ", $missing));
    }

    /** @return array<int, string> */
    private function sourceFiles(): array
    {
        $base = dirname(__DIR__, 2);
        $files = [];

        foreach (['app', 'resources/views'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base.'/'.$dir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    /**
     * The keys passed to the translation helpers as plain literals.
     *
     * @return array<int, string>
     */
    private function translatableLiterals(string $source): array
    {
        $keys = [];

        foreach ([$this->pattern(chr(39)), $this->pattern(chr(34))] as $pattern) {
            preg_match_all($pattern, $source, $matches);

            foreach ($matches[1] as $raw) {
                // Undo the PHP escaping so the key matches the JSON exactly.
                $keys[] = str_replace(
                    [chr(92).chr(39), chr(92).chr(34), chr(92).chr(92)],
                    [chr(39), chr(34), chr(92)],
                    $raw
                );
            }
        }

        return $keys;
    }

    /**
     * English is a real file too, carrying the same keys as Arabic.
     *
     * It held ten entries against Arabic's 1,908 for most of the project. The
     * panel still read correctly, because a JSON key that is missing falls back
     * to the key itself and every key here *is* its English sentence — which is
     * exactly why nobody noticed. The cost was not on screen: it was that
     * English copy could only be changed by editing code, while Arabic could be
     * changed in one file, so the two drifted by construction.
     *
     * Ten of those entries are real overrides and not placeholders — «Dashboard»
     * renders as «web Dashboard» in the sidebar because of one of them — so
     * filling the file is not the same as regenerating it.
     */
    #[Test]
    public function english_carries_the_same_keys_as_arabic(): void
    {
        $arabic = $this->arabic();

        $path = dirname(__DIR__, 2).'/resources/lang/en.json';
        $this->assertFileExists($path, 'the English translation file is missing');

        /** @var array<string, string> $english */
        $english = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $missing = array_values(array_diff(array_keys($arabic), array_keys($english)));
        sort($missing);

        $this->assertSame([], $missing, 'English is missing: '.implode("\n  ", $missing));

        $extra = array_values(array_diff(array_keys($english), array_keys($arabic)));
        sort($extra);

        $this->assertSame([], $extra, 'English has keys Arabic does not: '.implode("\n  ", $extra));
    }

    /**
     * No English value is blank.
     *
     * A key mapping to itself is the normal case and is correct — the key is the
     * English sentence. An empty string is not: it renders as nothing at all,
     * which is worse than the untranslated word it replaced.
     */
    #[Test]
    public function no_english_value_is_empty(): void
    {
        $path = dirname(__DIR__, 2).'/resources/lang/en.json';

        /** @var array<string, string> $english */
        $english = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $blank = [];

        foreach ($english as $key => $value) {
            if (trim($value) === '') {
                $blank[] = $key;
            }
        }

        $this->assertSame([], $blank, implode("\n  ", $blank));
    }

    /**
     * The portable copy of the panel's translations matches the real one.
     *
     * There are two files per language for the dashboard: `{code}.json`, which
     * is what `__()` reads, and `{code}_panel.json`, which is what the languages
     * screen offers for download and accepts on upload. Only the first was ever
     * written to. The second kept the ten scaffold keys it was created with, so
     * an operator who edited a translation and then exported got a file with
     * none of their work in it — and re-uploading it would have been a
     * catastrophe rather than a no-op.
     *
     * They are written together now. This is what stops them parting again.
     */
    #[Test]
    public function the_portable_panel_file_matches_the_one_the_panel_reads(): void
    {
        $base = dirname(__DIR__, 2).'/resources/lang';
        $drifted = [];

        foreach (['ar', 'en'] as $code) {
            $main = $base."/{$code}.json";
            $twin = $base."/{$code}_panel.json";

            $this->assertFileExists($main, "{$code}.json is missing");
            $this->assertFileExists($twin, "{$code}_panel.json is missing");

            /** @var array<string, string> $a */
            $a = json_decode((string) file_get_contents($main), true, 512, JSON_THROW_ON_ERROR);
            /** @var array<string, string> $b */
            $b = json_decode((string) file_get_contents($twin), true, 512, JSON_THROW_ON_ERROR);

            foreach (array_diff(array_keys($a), array_keys($b)) as $key) {
                $drifted[] = "{$code}_panel.json is missing: {$key}";
            }

            foreach (array_diff(array_keys($b), array_keys($a)) as $key) {
                $drifted[] = "{$code}_panel.json has a key {$code}.json does not: {$key}";
            }

            foreach ($a as $key => $value) {
                if (isset($b[$key]) && $b[$key] !== $value) {
                    $drifted[] = "{$code}: '{$key}' differs between the two files";
                }
            }
        }

        sort($drifted);

        $this->assertSame([], $drifted, implode("\n  ", $drifted));
    }

    /**
     * The extraction pattern for one quote style.
     *
     * Assembled rather than written out because it is mostly escaping, and a
     * regex full of backslashes is the kind of line that gets "tidied" into
     * something that silently matches nothing — which for this test would look
     * exactly like full coverage.
     */
    private function pattern(string $quote): string
    {
        $bs = chr(92);
        $q = preg_quote($quote, '/');

        return '/(?:__|trans|trans_choice|@lang)'.$bs.'('.$bs.'s*'.$q
            .'((?:[^'.$q.$bs.$bs.']|'.$bs.$bs.'.)*)'.$q.'/';
    }
}
