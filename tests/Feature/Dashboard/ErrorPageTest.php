<?php

namespace Tests\Feature\Dashboard;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The branded error pages, and the rule that makes them worth having.
 *
 * A pretty 500 page is easy. A 500 page that still renders when the database is
 * gone is the whole point, and it is the thing a later edit will break — by
 * adding a settings read, a `@csrf`, or an `auth()` call to what looks like an
 * ordinary Blade file.
 *
 * `CACHE_STORE` and `SESSION_DRIVER` are both `database` on this install, so a
 * database that is down takes the cache and the session with it. Any of those
 * three would throw *while rendering the error page*, and Laravel would fall
 * back to its bare built-in page — the design failing in exactly the case it
 * was built for.
 *
 * So the query-count assertions below are not style checks. They are the test.
 */
class ErrorPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedCore();
    }

    /**
     * Renders an error view and returns every query it ran doing so.
     *
     * @return array<int, string>
     */
    private function queriesWhileRendering(string $view, array $data = []): array
    {
        $queries = [];

        DB::listen(function ($q) use (&$queries): void {
            $queries[] = $q->sql;
        });

        view($view, $data)->render();

        return $queries;
    }

    #[Test]
    public function the_500_page_renders_without_touching_the_database(): void
    {
        // The one that matters. A database that is unreachable is the commonest
        // cause of the 500 this page exists to draw.
        $this->assertSame([], $this->queriesWhileRendering('errors.500'));
    }

    #[Test]
    public function no_error_page_touches_the_database(): void
    {
        // The 500 is the critical one, but a 503 is served during a deploy and a
        // 419 when the session has gone — neither is a good moment to need a
        // working database either. Keeping the whole set clean also means a
        // future page copied from a sibling starts out correct.
        foreach (['401', '403', '404', '419', '429', '500', '503'] as $code) {
            $this->assertSame(
                [],
                $this->queriesWhileRendering("errors.$code"),
                "errors/$code.blade.php queried the database"
            );
        }
    }

    #[Test]
    public function a_missing_page_gets_the_branded_404(): void
    {
        $response = $this->get('/a-url-that-does-not-exist');

        $response->assertNotFound();
        $response->assertSee('error-page', false);
        $response->assertSee(__('Page not found'));
    }

    #[Test]
    public function the_error_page_loads_none_of_the_panel_assets(): void
    {
        // `layouts.main` pulls 22 stylesheets and 30 scripts, and its topbar
        // composer reads the languages table on every render. An error page that
        // grew that chain would be both slow and fragile.
        $html = $this->get('/a-url-that-does-not-exist')->getContent();

        foreach (['app.css', 'theme.css', 'jquery', 'bootstrap.bundle', 'select2', 'apexcharts'] as $asset) {
            $this->assertStringNotContainsString($asset, $html, "the 404 page loaded $asset");
        }

        // What it should load instead.
        $this->assertStringContainsString('assets/css/landing.css', $html);
        $this->assertStringContainsString('assets/css/error.css', $html);
    }

    #[Test]
    public function the_page_carries_no_csrf_token(): void
    {
        // `@csrf` is the session, and the session is in the database. It is also
        // the easiest thing to paste in by habit when adding a form later.
        $html = $this->get('/a-url-that-does-not-exist')->getContent();

        $this->assertStringNotContainsString('csrf-token', $html);
        $this->assertStringNotContainsString('_token', $html);
    }

    #[Test]
    public function an_arabic_browser_gets_an_arabic_404_without_a_session(): void
    {
        // The gap this closes: an unmatched URL throws *before* route middleware,
        // so the `web` group — and `SetLocale` inside it — never runs. There is
        // no session to read a language choice out of, and the page came out
        // English however the visitor had set the site.
        $html = $this->withHeaders(['Accept-Language' => 'ar-EG,ar;q=0.9'])
            ->get('/a-url-that-does-not-exist')
            ->getContent();

        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('lang="ar"', $html);
        $this->assertStringContainsString('الصفحة غير موجودة', $html);
    }

    #[Test]
    public function an_english_browser_still_gets_english(): void
    {
        $html = $this->withHeaders(['Accept-Language' => 'en-GB,en;q=0.9'])
            ->get('/a-url-that-does-not-exist')
            ->getContent();

        $this->assertStringContainsString('dir="ltr"', $html);
        $this->assertStringContainsString('Page not found', $html);
    }

    #[Test]
    public function a_panel_404_keeps_the_panel_language_over_the_header(): void
    {
        // The other half of the rule. This request *did* reach a route —
        // `findOrFail` on a real one — so it went through the `web` group,
        // `SetLocale` set the panel's language, and there is a started session
        // saying so. The browser header must not overrule that.
        //
        // It is also why the composer keys off the session rather than comparing
        // the locale to `config('app.locale')`: `setLocale()` writes that config
        // key on its way past, so the two are equal by construction and the
        // comparison fires every time.
        $html = $this->actingAs($this->superAdmin())
            ->withHeaders(['Accept-Language' => 'ar-EG,ar;q=0.9'])
            ->get('/admin/order/show/999999')
            ->getContent();

        $this->assertStringContainsString('dir="ltr"', $html);
    }

    #[Test]
    public function the_html_element_keeps_the_landing_class(): void
    {
        // landing.css scopes its dark tokens and its 100% root font size to
        // `html.landing`. Without the class the page reads the light tokens and
        // renders at the panel's 87.5% zoom — a real regression that looks like
        // a styling accident rather than a missing attribute.
        $this->assertStringContainsString('class="landing"', view('errors.500')->render());
    }

    #[Test]
    public function the_api_is_untouched_by_any_of_this(): void
    {
        // Errors under api/* answer in the ApiResponse envelope, never HTML.
        // These views must not have changed that.
        $this->getJson('/api/v1/a-route-that-does-not-exist')
            ->assertNotFound()
            ->assertJsonStructure(['key', 'status', 'msg', 'code']);
    }
}
