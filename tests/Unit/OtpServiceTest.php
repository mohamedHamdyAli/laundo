<?php

namespace Tests\Unit;

use App\Modules\User\Models\User;
use App\Services\Auth\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The security properties of the one-time code, exercised directly so they are
 * proven independently of the route-level throttles that sit in front of them.
 */
class OtpServiceTest extends TestCase
{
    use RefreshDatabase;

    private OtpService $otp;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->otp = app(OtpService::class);
        $this->user = $this->customer();
    }

    public function test_the_code_is_stored_hashed_never_in_plain_text(): void
    {
        $code = $this->otp->issue($this->user);
        $stored = $this->user->fresh()->otp;

        $this->assertNotSame($code, $stored);
        $this->assertStringStartsWith('$2y$', $stored, 'the code must be a bcrypt hash');
    }

    public function test_the_code_is_hidden_from_serialization(): void
    {
        $this->otp->issue($this->user);

        $this->assertArrayNotHasKey('otp', $this->user->fresh()->toArray());
    }

    public function test_a_correct_code_is_accepted(): void
    {
        $code = $this->otp->issue($this->user);

        $this->assertTrue($this->otp->verify($this->user->fresh(), $code)['ok']);
    }

    public function test_a_code_can_only_be_used_once(): void
    {
        $code = $this->otp->issue($this->user);

        $this->assertTrue($this->otp->verify($this->user->fresh(), $code)['ok']);
        $this->assertSame('no_code', $this->otp->verify($this->user->fresh(), $code)['reason']);
    }

    public function test_an_expired_code_is_refused(): void
    {
        $code = $this->otp->issue($this->user);

        $this->user->forceFill(['otp_expires_at' => now()->subSecond()])->save();

        $this->assertSame('expired', $this->otp->verify($this->user->fresh(), $code)['reason']);
    }

    public function test_attempts_are_counted_and_the_code_burns(): void
    {
        // Six digits is a million combinations; without a counter an unthrottled
        // verify endpoint concedes in minutes.
        $code = $this->otp->issue($this->user);
        $max = (int) config('sms.otp.max_attempts');

        for ($i = 1; $i <= $max; $i++) {
            $this->assertSame('invalid', $this->otp->verify($this->user->fresh(), '000000')['reason']);
            $this->assertSame($i, $this->user->fresh()->otp_attempts);
        }

        $this->assertSame('too_many_attempts', $this->otp->verify($this->user->fresh(), '000000')['reason']);
        $this->assertNull($this->user->fresh()->otp, 'the code must be discarded once the limit is hit');

        // And the real code is worthless afterwards.
        $this->assertSame('no_code', $this->otp->verify($this->user->fresh(), $code)['reason']);
    }

    public function test_the_code_has_the_configured_length(): void
    {
        $code = $this->otp->issue($this->user);

        $this->assertSame((int) config('sms.otp.length'), strlen($code));
        $this->assertMatchesRegularExpression('/^\d+$/', $code);
    }

    /**
     * While SMS delivery is not integrated the code is deliberately fixed, so
     * the apps can be driven end to end without a provider account. Everything
     * else about it still holds — the tests above run against this same value.
     */
    public function test_the_configured_static_code_is_the_one_issued(): void
    {
        config(['sms.otp.static_code' => '123456']);

        $this->assertSame('123456', $this->otp->issue($this->user));
        $this->assertTrue($this->otp->verify($this->user->fresh(), '123456')['ok']);
    }

    /**
     * A static code that is not exactly `length` digits is ignored rather than
     * honoured: it would be a code the `digits:6` request rules refuse, which
     * would leave accounts that could never be verified.
     */
    public function test_a_malformed_static_code_falls_back_to_a_random_one(): void
    {
        foreach (['', '12345', 'abcdef', '1234567'] as $bad) {
            config(['sms.otp.static_code' => $bad]);

            // A fresh instance per pass, the way a request always has one: the
            // forceFill in issue() compares against what its instance last read,
            // so re-issuing on an object whose code has since been burned
            // elsewhere would leave the new expiry unwritten.
            $user = $this->user->fresh();
            $code = $this->otp->issue($user);

            $this->assertNotSame($bad, $code);
            $this->assertSame((int) config('sms.otp.length'), strlen($code));
            $this->assertTrue($this->otp->verify($user->fresh(), $code)['ok'], "static_code=[{$bad}]");
        }
    }

    public function test_verifying_with_no_code_issued_is_refused(): void
    {
        $this->assertSame('no_code', $this->otp->verify($this->user, '123456')['reason']);
    }
}
