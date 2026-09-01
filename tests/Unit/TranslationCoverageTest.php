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
}
