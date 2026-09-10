<?php

namespace Tests\Feature\Dashboard;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The panel's hand-edited assets carry a version stamp.
 *
 * This exists because of a specific deploy. `theme.css` and `custom.js` are
 * edited by hand, have no build step, no content hash in the filename, and sit
 * behind Cloudflare — so a release that changed both shipped Blade referring
 * to rules and behaviour the cached files did not have. Two visible faults on
 * the live site at once: the sidebar's new counts rendered as bare numbers
 * because `.menu-badge` did not exist yet in the stylesheet the browser held,
 * and the splash — which is `position: fixed` in the new one — painted as a
 * full-size logo across the top of every page, over content nobody could
 * click.
 *
 * Nothing about that was a code bug. It was an asset with no way to say it had
 * changed. `assetVersion()` stamps `filemtime()` onto the URL, so a deploy
 * that touches the file changes the URL and the cache misses on its own.
 *
 * The rule this pins: **an asset this project edits by hand must be
 * versioned.** Vendor files under `public/assets/extensions/**` and the
 * template's own `main/app.css` are not — they change when the template is
 * replaced, which is not a thing that happens on a Tuesday.
 */
class AssetCacheBustingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->seedCore();
    }

    /**
     * The files this project actually edits, and where they are referenced.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public static function handEditedAssets(): array
    {
        return [
            ['assets/css/theme.css', 'the panel override stylesheet'],
            ['assets/css/custom.css', 'the second override stylesheet'],
            ['assets/js/custom/custom.js', 'the panel behaviour script'],
            ['assets/js/custom/common.js', 'the shared AJAX helpers'],
            ['assets/js/custom/notifications.js', 'the topbar bell poller'],
            ['assets/js/custom/form-validation.js', 'the form handler that keeps what you typed'],
            ['assets/js/custom/brand-loader.js', 'the splash'],
        ];
    }

    #[Test]
    public function every_hand_edited_asset_is_versioned_on_a_panel_page(): void
    {
        $html = $this->actingAs($this->superAdmin())
            ->get(route('home'))
            ->assertOk()
            ->getContent();

        foreach (self::handEditedAssets() as [$path, $what]) {
            $this->assertMatchesRegularExpression(
                '~'.preg_quote($path, '~').'\?v=\d+~',
                $html,
                "{$path} ({$what}) is referenced without a ?v= stamp — a deploy that changes it "
                .'leaves every returning visitor on the cached copy.'
            );
        }
    }

    #[Test]
    public function the_splash_survives_its_stylesheet_not_arriving(): void
    {
        // The failure that started this: with `theme.css` cached and stale,
        // the overlay had no rules and a 240px logo rendered inline across the
        // top of the page. Enough layout is inline that it cannot happen
        // again, whatever the stylesheet does or does not say.
        $html = $this->actingAs($this->superAdmin())
            ->get(route('home'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '~id="brand-loader"[^>]*style="[^"]*position:fixed~',
            $html,
            'the splash must lay itself out without depending on theme.css'
        );
    }

    #[Test]
    public function the_stamp_changes_when_the_file_does(): void
    {
        $path = public_path('assets/css/theme.css');
        $before = assetVersion('css/theme.css');

        $original = filemtime($path);

        try {
            touch($path, $original + 60);
            clearstatcache(true, $path);

            $this->assertNotSame($before, assetVersion('css/theme.css'));
        } finally {
            // Put the file's own timestamp back: a test must not leave the
            // working tree looking like a deploy happened.
            touch($path, $original);
            clearstatcache(true, $path);
        }
    }

    #[Test]
    public function a_missing_file_does_not_bring_the_page_down(): void
    {
        // A wrong asset URL is a visible bug; an exception on a panel page is
        // an outage. The helper falls back rather than throwing.
        $this->assertNotSame('', assetVersion('css/this-file-does-not-exist.css'));
    }
}
