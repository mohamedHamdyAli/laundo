<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
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
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['key', 'status', 'msg', 'code', 'data' => ['status', 'time', 'locale', 'timezone']]);
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

    public function test_status_says_success_on_every_2xx(): void
    {
        foreach (['/api/v1/ping', '/api/v1/services', '/api/v1/cities', '/api/v1/app-settings'] as $url) {
            $this->withHeaders($this->apiHeaders())->getJson($url)
                ->assertOk()
                ->assertJsonPath('status', 'success');
        }
    }

    public function test_status_says_error_on_every_failure_band(): void
    {
        // One per band the API actually answers with, because `status` is
        // derived from the code and a band nobody tested is a band that could
        // silently report success.
        $cases = [
            // 401 — no token on a protected route
            ['GET', '/api/v1/orders', [], 401],
            // 404 — no such route
            ['GET', '/api/v1/no-such-endpoint', [], 404],
            // 422 — validation
            ['POST', '/api/v1/auth/login', [], 422],
        ];

        foreach ($cases as [$method, $url, $payload, $expected]) {
            $response = $this->withHeaders($this->apiHeaders())
                ->json($method, $url, $payload);

            $response->assertStatus($expected)
                ->assertJsonPath('status', 'error')
                ->assertJsonPath('code', $expected);
        }
    }

    public function test_status_is_derived_from_the_code_and_cannot_contradict_it(): void
    {
        // The point of deriving it: there is no call site that can set one and
        // forget the other. Asserted directly on the helper across the whole
        // vocabulary of codes this API uses.
        foreach ([200, 201] as $ok) {
            $this->assertSame('success', apiResponseStatus($ok), "code {$ok}");
        }

        foreach ([400, 401, 403, 404, 422, 429, 500] as $bad) {
            $this->assertSame('error', apiResponseStatus($bad), "code {$bad}");
        }
    }

    public function test_a_paginated_list_sends_page_info_and_never_markup_in_the_message(): void
    {
        // A paginator stringifies itself by *rendering the Blade pagination
        // view*. Put one in the message slot — which every call site in this API
        // did — and the response is a page of Bootstrap `<nav>` markup where a
        // client expects a sentence, with `meta` empty and nothing in the log.
        $paginator = new LengthAwarePaginator([['id' => 1], ['id' => 2]], 30, 15, 1);

        $body = successReturnPaginated([['id' => 1], ['id' => 2]], $paginator)->getData(true);

        $this->assertSame('', $body['msg']);
        $this->assertSame([
            'current_page' => 1,
            'per_page' => 15,
            'has_more' => true,
            'total' => 30,
            'last_page' => 2,
        ], $body['meta']);
        $this->assertCount(2, $body['data']);
    }

    public function test_a_paginator_on_its_own_sends_its_own_items(): void
    {
        // The short form, for a list that needs no presenter.
        $body = successReturnPaginated(new LengthAwarePaginator([['id' => 7]], 1, 15, 1))->getData(true);

        $this->assertSame([['id' => 7]], $body['data']);
        $this->assertSame(1, $body['meta']['total']);
        $this->assertFalse($body['meta']['has_more']);
    }

    public function test_the_envelope_status_does_not_shadow_a_status_inside_data(): void
    {
        // `ping` carries its own `data.status`, and the two live at different
        // depths on purpose — the envelope says whether the call worked, the
        // payload says what the thing's state is.
        $this->withHeaders($this->apiHeaders())->getJson('/api/v1/ping')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.status', 'ok');
    }
}
