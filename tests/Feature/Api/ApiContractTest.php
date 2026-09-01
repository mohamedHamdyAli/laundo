<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P0 — the response envelope, locale resolution and error shapes that every
 * later endpoint inherits.
 */
class ApiContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
    }

    public function test_ping_returns_the_envelope(): void
    {
        $response = $this->withHeaders($this->apiHeaders())->getJson('/api/v1/ping');

        $response->assertOk()
            ->assertJsonPath('key', 'success')
            ->assertJsonPath('code', 200)
            ->assertJsonStructure(['key', 'msg', 'code', 'data' => ['status', 'time', 'locale', 'timezone']]);
    }

    public function test_envelope_code_matches_the_http_status(): void
    {
        // The whole point of the P0 fix: a body claiming 401 while the transport
        // says 200 makes every client either wrong or defensive.
        $response = $this->withHeaders($this->apiHeaders())->getJson('/api/v1/me');

        $response->assertStatus(401)->assertJsonPath('code', 401)->assertJsonPath('key', 'not_auth');
    }

    public function test_lang_header_switches_the_locale(): void
    {
        $this->withHeaders($this->apiHeaders('ar'))->getJson('/api/v1/ping')
            ->assertJsonPath('data.locale', 'ar');

        $this->withHeaders($this->apiHeaders('en'))->getJson('/api/v1/ping')
            ->assertJsonPath('data.locale', 'en');
    }

    public function test_unknown_lang_falls_back_to_the_default(): void
    {
        $this->withHeaders($this->apiHeaders('zz'))->getJson('/api/v1/ping')
            ->assertJsonPath('data.locale', 'en');
    }

    public function test_missing_route_answers_json_not_html(): void
    {
        $this->withHeaders($this->apiHeaders())->getJson('/api/v1/no-such-endpoint')
            ->assertStatus(404)
            ->assertJsonPath('key', 'not_found');
    }

    public function test_protected_route_without_a_token_is_json_401_not_a_redirect(): void
    {
        // Without the api-specific exception rendering this would be a 302 to the
        // login page, which a mobile client cannot interpret.
        $this->withHeaders($this->apiHeaders())->getJson('/api/v1/profile')
            ->assertStatus(401)
            ->assertJsonPath('key', 'not_auth');
    }

    public function test_bogus_bearer_token_is_rejected(): void
    {
        $this->withHeaders($this->apiHeaders() + ['Authorization' => 'Bearer 999|not-a-real-token'])
            ->getJson('/api/v1/profile')
            ->assertStatus(401);
    }

    public function test_validation_failure_uses_the_errors_shape(): void
    {
        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/auth/login', ['phone' => 'not-a-phone'])
            ->assertStatus(422)
            ->assertJsonPath('key', 'validation_error')
            ->assertJsonStructure(['key', 'msg', 'code', 'errors' => ['phone']]);
    }

    public function test_languages_endpoint_casts_enum_strings_to_booleans(): void
    {
        // languages.default and is_rtl are enum('true','false') strings in this
        // schema. Leaking that to a client would be a trap.
        $response = $this->withHeaders($this->apiHeaders())->getJson('/api/v1/languages');

        $response->assertOk();

        $arabic = collect($response->json('data'))->firstWhere('code', 'ar');

        $this->assertTrue($arabic['is_rtl'], 'ar must report is_rtl as a real boolean true');
        $this->assertFalse($arabic['is_default']);
    }
}
