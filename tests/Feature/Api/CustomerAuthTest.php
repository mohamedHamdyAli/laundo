<?php

namespace Tests\Feature\Api;

use App\Modules\User\Models\User;
use App\Services\Auth\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * P4 — registration, verification and sign-in.
 */
class CustomerAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->seedGeo();
    }

    /**
     * @return array<string, string>
     */
    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New Customer',
            'phone' => '+201011223344',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'accepted_terms' => '1',
        ], $overrides);
    }

    public function test_registration_without_an_email_succeeds(): void
    {
        // This was a 500 until users.email was made nullable: the design marks
        // email optional but the column was NOT NULL.
        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/auth/register', $this->registrationPayload())
            ->assertStatus(201)
            ->assertJsonPath('key', 'success');

        $this->assertDatabaseHas('users', ['phone' => '+201011223344', 'email' => null]);
    }

    public function test_registration_leaves_the_account_unverified(): void
    {
        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/auth/register', $this->registrationPayload());

        $this->assertNull(User::where('phone', '+201011223344')->first()->phone_verified_at);
    }

    public function test_registration_never_returns_the_code(): void
    {
        $response = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/auth/register', $this->registrationPayload());

        $body = $response->getContent();

        $this->assertStringNotContainsString('otp', strtolower($body));
        $this->assertArrayNotHasKey('code', $response->json('data'));
    }

    public function test_a_taken_phone_is_refused(): void
    {
        $this->customer('+201011223344');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/auth/register', $this->registrationPayload())
            ->assertStatus(422)
            ->assertJsonPath('key', 'validation_error')
            ->assertJsonStructure(['errors' => ['phone']]);
    }

    public function test_terms_must_be_accepted(): void
    {
        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/auth/register', $this->registrationPayload(['accepted_terms' => '0']))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['accepted_terms']]);
    }

    /**
     * The agreement used to be validated and then thrown away, so the only
     * evidence anybody had consented was that the request had not been refused
     * — which answers nothing if the question is ever asked.
     */
    public function test_accepting_the_terms_is_recorded_with_the_moment(): void
    {
        $before = now()->subSecond();

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/auth/register', $this->registrationPayload())
            ->assertCreated();

        $user = User::where('phone', '+201011223344')->firstOrFail();

        $this->assertNotNull($user->accepted_terms_at);
        $this->assertTrue($user->accepted_terms_at->greaterThanOrEqualTo($before));
    }

    /**
     * And a refused registration records nothing — there is no account to
     * record it against, and a consent row without an account would be a
     * consent nobody gave.
     */
    public function test_a_refused_registration_records_no_consent(): void
    {
        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/auth/register', $this->registrationPayload(['accepted_terms' => '0']))
            ->assertStatus(422);

        $this->assertDatabaseMissing('users', ['phone' => '+201011223344']);
    }

    /**
     * A non-Egyptian number used to be refused outright. It is accepted now, by
     * decision: the market is not settled, and the design's country picker
     * implied a choice the validation then denied.
     */
    public function test_a_number_from_another_country_is_accepted(): void
    {
        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/auth/register', $this->registrationPayload(['phone' => '+96555512345']))
            ->assertCreated();

        $this->assertDatabaseHas('users', ['phone' => '+96555512345']);
    }

    /**
     * What replaced it. The country code is mandatory rather than optional
     * because `01012345678` is an Egyptian mobile *and* a valid Italian
     * landline — and the phone is the account's identity here, so one that can
     * be read two ways is one that can split a person across two accounts.
     */
    public function test_a_number_without_a_country_code_is_refused(): void
    {
        foreach (['01012345678', '201012345678', '1012345678'] as $bare) {
            $this->withHeaders($this->apiHeaders())
                ->postJson('/api/v1/auth/register', $this->registrationPayload(['phone' => $bare]))
                ->assertStatus(422)
                ->assertJsonStructure(['errors' => ['phone']]);
        }

        // None of the three created an account.
        $this->assertSame(0, User::whereIn('phone', ['01012345678', '201012345678', '1012345678'])->count());
    }

    public function test_login_is_refused_before_verification(): void
    {
        $user = $this->customer('+201011223344', verified: false);
        $user->forceFill(['password' => Hash::make('secret123')])->save();

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/auth/login', ['phone' => '+201011223344', 'password' => 'secret123'])
            ->assertStatus(403)
            ->assertJsonPath('key', 'forbidden');
    }

    public function test_login_is_refused_for_an_inactive_account(): void
    {
        $user = $this->customer('+201011223344');
        $user->forceFill(['password' => Hash::make('secret123'), 'status' => 'inactive'])->save();

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/auth/login', ['phone' => '+201011223344', 'password' => 'secret123'])
            ->assertStatus(403);
    }

    public function test_wrong_password_and_unknown_number_answer_identically(): void
    {
        // Otherwise the endpoint tells an attacker which numbers are registered.
        $user = $this->customer('+201011223344');
        $user->forceFill(['password' => Hash::make('secret123')])->save();

        $wrongPassword = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/auth/login', ['phone' => '+201011223344', 'password' => 'wrong-one']);

        $unknownNumber = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/auth/login', ['phone' => '+201055554444', 'password' => 'secret123']);

        $this->assertSame($wrongPassword->status(), $unknownNumber->status());
        $this->assertSame($wrongPassword->json('msg'), $unknownNumber->json('msg'));
    }

    public function test_verify_then_login_issues_a_working_token(): void
    {
        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/auth/register', $this->registrationPayload());

        $user = User::where('phone', '+201011223344')->first();
        $code = app(OtpService::class)->issue($user);

        $verify = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/auth/verify-otp', ['phone' => '+201011223344', 'code' => $code]);

        $verify->assertOk()->assertJsonPath('data.user.phone_verified', true);

        $token = $verify->json('data.token');
        $this->assertNotEmpty($token);

        $this->withHeaders($this->apiHeaders() + ['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('data.phone', '+201011223344');
    }

    public function test_logout_revokes_only_the_calling_token(): void
    {
        $user = $this->customer('+201011223344');

        $first = $user->createToken('device-one')->plainTextToken;
        $second = $user->createToken('device-two')->plainTextToken;

        $this->withHeaders($this->apiHeaders() + ['Authorization' => "Bearer {$first}"])
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        // The guard caches the resolved user for the lifetime of the container,
        // which in tests spans every request in the method. Production gets a
        // fresh container per request; here it has to be asked for explicitly, or
        // the next call would pass on the cached user and prove nothing.
        $this->app['auth']->forgetGuards();

        $this->withHeaders($this->apiHeaders() + ['Authorization' => "Bearer {$first}"])
            ->getJson('/api/v1/profile')->assertStatus(401);

        $this->app['auth']->forgetGuards();

        // Signing out on one phone must not sign the customer out on their tablet.
        $this->withHeaders($this->apiHeaders() + ['Authorization' => "Bearer {$second}"])
            ->getJson('/api/v1/profile')->assertOk();
    }

    public function test_resend_answers_the_same_for_unknown_numbers(): void
    {
        $known = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/auth/resend-otp', ['phone' => $this->customer('+201011223344')->phone]);

        $unknown = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/auth/resend-otp', ['phone' => '+201099990000']);

        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame($known->json('msg'), $unknown->json('msg'));
    }

    /**
     * The two-step reset, end to end. The code is spent on `verify-reset-code`
     * and the password step presents only the ticket that step returned.
     */
    public function test_password_reset_revokes_every_token(): void
    {
        $user = $this->customer('+201011223344');
        $user->createToken('a');
        $user->createToken('b');
        $this->assertSame(2, $user->tokens()->count());

        $code = app(OtpService::class)->issue($user);

        $token = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/auth/verify-reset-code', [
                'phone' => '+201011223344',
                'code' => $code,
            ])
            ->assertOk()
            ->json('data.reset_token');

        $this->assertIsString($token);

        $this->withHeaders($this->apiHeaders())->postJson('/api/v1/auth/reset-password', [
            'reset_token' => $token,
            'password' => 'brandnew123',
            'password_confirmation' => 'brandnew123',
        ])->assertOk();

        $this->assertSame(0, $user->fresh()->tokens()->count());
        $this->assertTrue(Hash::check('brandnew123', $user->fresh()->password));
    }

    /**
     * The ticket is never the code. Sending the six digits to the password step
     * is what the old single-call shape allowed, and it must not work.
     */
    public function test_the_password_step_will_not_accept_the_code(): void
    {
        $user = $this->customer('+201011223344');
        $code = app(OtpService::class)->issue($user);

        $this->withHeaders($this->apiHeaders())->postJson('/api/v1/auth/reset-password', [
            'reset_token' => $code,
            'password' => 'brandnew123',
            'password_confirmation' => 'brandnew123',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    /**
     * Single use. A ticket that has already bought a password change cannot buy
     * a second one — which is what makes a captured ticket worth nothing after
     * the person who earned it has finished.
     */
    public function test_a_reset_ticket_cannot_be_spent_twice(): void
    {
        $user = $this->customer('+201011223344');
        $code = app(OtpService::class)->issue($user);

        $token = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/auth/verify-reset-code', ['phone' => '+201011223344', 'code' => $code])
            ->assertOk()->json('data.reset_token');

        $this->withHeaders($this->apiHeaders())->postJson('/api/v1/auth/reset-password', [
            'reset_token' => $token,
            'password' => 'brandnew123',
            'password_confirmation' => 'brandnew123',
        ])->assertOk();

        $this->withHeaders($this->apiHeaders())->postJson('/api/v1/auth/reset-password', [
            'reset_token' => $token,
            'password' => 'thirdpassword123',
            'password_confirmation' => 'thirdpassword123',
        ])->assertStatus(422);

        // Still the password the first, legitimate call set.
        $this->assertTrue(Hash::check('brandnew123', $user->fresh()->password));
    }

    /**
     * The code is consumed by the verify step, so it cannot be verified again
     * to mint a second ticket.
     */
    public function test_a_reset_code_is_consumed_by_the_verify_step(): void
    {
        $user = $this->customer('+201011223344');
        $code = app(OtpService::class)->issue($user);

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/auth/verify-reset-code', ['phone' => '+201011223344', 'code' => $code])
            ->assertOk();

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/auth/verify-reset-code', ['phone' => '+201011223344', 'code' => $code])
            ->assertStatus(422);
    }

    /**
     * An expired ticket is refused, and refused the same way as one that never
     * existed.
     */
    public function test_an_expired_reset_ticket_is_refused(): void
    {
        $user = $this->customer('+201011223344');
        $code = app(OtpService::class)->issue($user);

        $token = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/auth/verify-reset-code', ['phone' => '+201011223344', 'code' => $code])
            ->assertOk()->json('data.reset_token');

        $this->travel((int) config('sms.password_reset_token.ttl_seconds', 600) + 5)->seconds();

        $expired = $this->withHeaders($this->apiHeaders())->postJson('/api/v1/auth/reset-password', [
            'reset_token' => $token,
            'password' => 'brandnew123',
            'password_confirmation' => 'brandnew123',
        ])->assertStatus(422);

        $unknown = $this->withHeaders($this->apiHeaders())->postJson('/api/v1/auth/reset-password', [
            'reset_token' => str_repeat('a', 64),
            'password' => 'brandnew123',
            'password_confirmation' => 'brandnew123',
        ])->assertStatus(422);

        $this->assertSame($unknown->json('msg'), $expired->json('msg'));
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    /**
     * The ticket is not an access token. Presenting it as a bearer credential
     * must not reach anything.
     */
    public function test_a_reset_ticket_is_not_an_access_token(): void
    {
        $user = $this->customer('+201011223344');
        $code = app(OtpService::class)->issue($user);

        $token = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/auth/verify-reset-code', ['phone' => '+201011223344', 'code' => $code])
            ->assertOk()->json('data.reset_token');

        $this->withHeaders($this->apiHeaders() + ['Authorization' => 'Bearer '.$token])
            ->getJson('/api/v1/me')
            ->assertUnauthorized();
    }
}
