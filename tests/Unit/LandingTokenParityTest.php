<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `landing.css` copies the design tokens out of `theme.css`. This is what makes
 * that safe.
 *
 * The landing page cannot load `theme.css` — it is 93 KB, and the page would
 * need `app.css` (399 KB) under it for the Bootstrap variables theme.css
 * remaps. Nor can it `@import` it, which is a render-blocking serial request on
 * the one page whose paint time is measured. So the tokens are duplicated.
 *
 * Duplication without a check is a slow leak: somebody rebrands the panel, the
 * public site keeps the old blue, and nobody notices for a month because both
 * look deliberate on their own. `lessons.md` has this exact shape twice — the
 * palette swap that had to enumerate every pairing, and the `height: 36px`
 * override that outlived the value it matched, "both numbers literals in
 * different files".
 *
 * So: same name, same value, or a red build.
 */
class LandingTokenParityTest extends TestCase
{
    private const THEME = 'public/assets/css/theme.css';

    private const LANDING = 'public/assets/css/landing.css';

    /**
     * Every custom property declared in a light-theme `:root` block.
     *
     * theme.css has **six** separate `:root` blocks and four
     * `body.theme-dark` ones; only the former are read, because the dark values
     * are deliberately different per surface and are not what this compares.
     *
     * @return array<string, string>
     */
    private function rootTokens(string $relativePath): array
    {
        $path = dirname(__DIR__, 2).'/'.$relativePath;

        $this->assertFileExists($path, "{$relativePath} is missing");

        $css = (string) file_get_contents($path);

        // Comments first: theme.css quotes hex values in prose, and a token
        // name inside a comment would otherwise be read as a declaration.
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        $tokens = [];

        // Bare `:root {` only. `html.landing.theme-dark`, `:root:not(...)` and
        // anything inside an @media block with a different selector are skipped
        // by the anchor on the selector itself.
        preg_match_all('/(?<![\w.\[:-])(:root)\s*\{([^}]*)\}/', $css, $blocks, PREG_SET_ORDER);

        foreach ($blocks as $block) {
            preg_match_all('/(--[\w-]+)\s*:\s*([^;]+);/', $block[2], $declarations, PREG_SET_ORDER);

            foreach ($declarations as $declaration) {
                // Last declaration wins, as the cascade would have it.
                $tokens[$declaration[1]] = $this->normalise($declaration[2]);
            }
        }

        return $tokens;
    }

    /**
     * Compare values, not formatting.
     *
     * `rgba(16, 24, 40, .04)` and `rgba(16,24,40,0.04)` are the same colour, and
     * a test that fails on the space is a test somebody switches off.
     */
    private function normalise(string $value): string
    {
        $value = strtolower(trim($value));
        $value = (string) preg_replace('/\s+/', ' ', $value);
        $value = (string) preg_replace('/\s*,\s*/', ',', $value);

        // `0.04` -> `.04`, so either spelling matches.
        $value = (string) preg_replace('/\b0\.(\d)/', '.$1', $value);

        // Expand #abc to #aabbcc so the two spellings of a colour agree.
        if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $value, $short) === 1) {
            $value = '#'.$short[1].$short[1].$short[2].$short[2].$short[3].$short[3];
        }

        return $value;
    }

    #[Test]
    public function the_shared_tokens_hold_identical_values(): void
    {
        $theme = $this->rootTokens(self::THEME);
        $landing = $this->rootTokens(self::LANDING);

        $this->assertNotEmpty($theme, 'no :root tokens were parsed out of theme.css');
        $this->assertNotEmpty($landing, 'no :root tokens were parsed out of landing.css');

        $shared = array_intersect_key($theme, $landing);

        $this->assertNotEmpty($shared, 'landing.css and theme.css share no token names at all');

        $drifted = [];

        foreach ($shared as $name => $themeValue) {
            if ($landing[$name] !== $themeValue) {
                $drifted[] = "{$name}: theme.css has '{$themeValue}', landing.css has '{$landing[$name]}'";
            }
        }

        $this->assertSame(
            [],
            $drifted,
            "design tokens have drifted between theme.css and landing.css:\n  "
            .implode("\n  ", $drifted)
            ."\ntheme.css is canonical — copy its values across."
        );
    }

    #[Test]
    public function the_landing_page_carries_the_brand_tokens_it_needs(): void
    {
        // A guard on the parser as much as on the file: if a refactor moved the
        // token block somewhere the regex cannot see, the test above would pass
        // on an empty intersection and prove nothing. This names the ones that
        // must be there.
        $landing = $this->rootTokens(self::LANDING);

        foreach ([
            '--brand-navy',
            '--brand-navy-2',
            '--brand-primary',
            '--brand-primary-dark',
            '--brand-primary-soft',
            '--brand-text',
            '--surface-bg',
            '--surface-card',
            '--surface-border',
            '--text-strong',
            '--text-muted',
            '--tone-ok',
            '--tone-warn',
            '--tone-live',
        ] as $token) {
            $this->assertArrayHasKey($token, $landing, "landing.css no longer declares {$token}");
        }
    }

    #[Test]
    public function the_landing_page_does_not_reference_the_admin_stylesheets(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/'.self::LANDING);

        // Comments stripped first: this file's header names theme.css several
        // times on purpose, and the point of the assertion is that no *rule*
        // reaches for it.
        $rules = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        // An @import would reintroduce the payload this file exists to avoid,
        // and would do it as a serial, render-blocking request.
        $this->assertStringNotContainsString('@import', $rules);

        foreach (['theme.css', 'main/app.css', 'main/rtl.css', 'custom.css', 'bootstrap-icons'] as $admin) {
            $this->assertStringNotContainsString($admin, $rules, "landing.css must not reach for {$admin}");
        }
    }
}
