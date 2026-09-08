<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The site runs behind Cloudflare, which terminates TLS and forwards to the
 * origin over plain HTTP.
 *
 * Until `trustProxies()` was configured, Laravel never learned a request was
 * secure, so every absolute URL it generated came out `http://`. The visible
 * consequence was that **search failed on every list screen**: each index view
 * wires its box with `url: "{{ route('admin.x.search') }}"`, and a page loaded
 * over https asking for an http sub-resource is mixed content, which browsers
 * block outright. jQuery's `error` branch fired and the table read «Error
 * during search».
 *
 * Nothing reached the server. `laravel.log` was clean, and `curl` of the same
 * endpoint returned a healthy 200 — curl has no mixed-content policy — which is
 * exactly why this looked like a database fault for a while.
 *
 * These tests pin both halves of the header contract. The scheme is what broke
 * search; the client IP is what silently broke rate limiting.
 */
class TrustedProxyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->seedCore();
    }

    #[Test]
    public function a_forwarded_https_request_generates_https_urls(): void
    {
        $html = (string) $this->get('/', ['X-Forwarded-Proto' => 'https'])
            ->assertOk()
            ->getContent();

        // The canonical is generated with url(), so it is a faithful witness for
        // every other absolute URL on the page.
        $this->assertStringContainsString('rel="canonical" href="https://', $html);
        $this->assertStringNotContainsString('rel="canonical" href="http://', $html);
    }

    #[Test]
    public function the_search_url_a_list_screen_wires_is_https(): void
    {
        // The actual regression, on the actual screen it was reported from.
        $html = (string) $this->actingAs($this->superAdmin())
            ->get('/admin/city', ['X-Forwarded-Proto' => 'https'])
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('https://localhost/admin/city/search', $html);
        $this->assertStringNotContainsString('http://localhost/admin/city/search', $html);
    }

    #[Test]
    public function every_list_screen_wires_an_https_search_url(): void
    {
        // "بايظ في كل الصفح" — it was every screen, so assert more than one.
        foreach (['country', 'zone', 'service', 'user', 'offer'] as $module) {
            $html = (string) $this->actingAs($this->superAdmin())
                ->get("/admin/{$module}", ['X-Forwarded-Proto' => 'https'])
                ->assertOk()
                ->getContent();

            $this->assertStringNotContainsString(
                "http://localhost/admin/{$module}/search",
                $html,
                "{$module}'s search URL is http:// on an https request — mixed content, and the browser will block it"
            );
        }
    }

    #[Test]
    public function a_plain_http_request_still_generates_http_urls(): void
    {
        // The counterpart. Forcing https unconditionally would have passed every
        // assertion above and broken local development, so the scheme has to
        // follow the header rather than be hardcoded.
        $html = (string) $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('rel="canonical" href="http://', $html);
    }

    #[Test]
    public function cloudflares_own_header_is_what_actually_marks_the_request_secure(): void
    {
        // The real production shape, measured on the live origin: Cloudflare is
        // in Flexible SSL mode, so `X-Forwarded-Proto` truthfully says `http`
        // for the CF->origin hop, and the visitor's scheme survives only in
        // `CF-Visitor`. Trusting proxies alone reads "http" and changes nothing,
        // which is why the first attempt at this fix did not work.
        $html = (string) $this->get('/', [
            'CF-Visitor' => '{"scheme":"https"}',
            'X-Forwarded-Proto' => 'http',
            'X-Forwarded-Host' => 'laundo.nahrdev.net',
        ])->assertOk()->getContent();

        $this->assertStringContainsString('href="https://', $html);
        $this->assertStringNotContainsString('rel="canonical" href="http://', $html);
    }

    #[Test]
    public function a_list_screens_search_url_is_https_behind_cloudflare(): void
    {
        // The reported bug, in the exact header conditions that caused it.
        $html = (string) $this->actingAs($this->superAdmin())
            ->get('/admin/service', [
                'CF-Visitor' => '{"scheme":"https"}',
                'X-Forwarded-Proto' => 'http',
            ])
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('https://localhost/admin/service/search', $html);
        $this->assertStringNotContainsString('http://localhost/admin/service/search', $html);
    }

    #[Test]
    public function it_never_upgrades_a_request_cloudflare_says_was_plain(): void
    {
        // Only ever upward. A visitor genuinely on http must not be told they
        // are on https, or every generated link breaks in the other direction.
        $html = (string) $this->get('/', ['CF-Visitor' => '{"scheme":"http"}'])
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('rel="canonical" href="http://', $html);
    }

    #[Test]
    public function a_malformed_cloudflare_header_is_ignored(): void
    {
        // Decoded, not string-matched: a substring test would fire on anything
        // that merely mentioned https, and this decides whether the whole
        // application treats the connection as secure.
        foreach (['not json', '{"scheme":"HTTPS"}', '{}', '', 'https'] as $value) {
            $html = (string) $this->get('/', ['CF-Visitor' => $value])
                ->assertOk()
                ->getContent();

            $this->assertStringContainsString(
                'rel="canonical" href="http://',
                $html,
                "CF-Visitor: {$value} should not have been read as https"
            );
        }
    }

    #[Test]
    public function the_client_ip_comes_from_the_forwarded_header(): void
    {
        // Not cosmetic. `AppServiceProvider`'s `otp`, `otp-verify`, `login` and
        // `location` limiters key on $request->ip(); without this every visitor
        // shares Cloudflare's address, so one person hitting the OTP limit locks
        // out everybody.
        $this->get('/up', [
            'X-Forwarded-For' => '203.0.113.7',
            'X-Forwarded-Proto' => 'https',
        ])->assertOk();

        $this->assertSame('203.0.113.7', request()->ip());
    }

    #[Test]
    public function the_forwarded_host_is_honoured(): void
    {
        $this->get('/up', [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'laundo.nahrdev.net',
        ])->assertOk();

        $this->assertSame('laundo.nahrdev.net', request()->getHost());
        $this->assertTrue(request()->isSecure());
    }
}
