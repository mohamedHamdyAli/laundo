<?php

namespace App\Services\Auth;

use App\Modules\User\Models\User;
use App\Services\Sms\SmsSender;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Issues and verifies one-time codes.
 *
 * Three properties this class is responsible for, all of them security-relevant:
 *
 *   1. **The code is stored hashed.** A plaintext six-digit code sitting in a
 *      column that any dashboard query can read is a credential in the clear.
 *      Hashing costs nothing here because verification only ever compares.
 *   2. **Attempts are counted.** Six digits is a million combinations, which an
 *      unthrottled verify endpoint concedes in minutes. After `max_attempts` the
 *      code is burned and a new one must be requested.
 *   3. **A code is single-use.** Consuming it clears the columns, so a captured
 *      code cannot be replayed.
 */
class OtpService
{
    public function __construct(private readonly SmsSender $sms) {}

    /**
     * Generate a fresh code, store its hash, and send the plaintext once.
     *
     * Returns the plaintext only so tests and the log driver can observe it —
     * it is never persisted and never returned through the API.
     */
    public function issue(User $user): string
    {
        $length = (int) config('sms.otp.length', 6);
        $ttl = (int) config('sms.otp.ttl_seconds', 120);

        $code = $this->staticCode($length) ?? $this->randomCode($length);

        $user->forceFill([
            'otp' => Hash::make($code),
            'otp_expires_at' => now()->addSeconds($ttl),
            'otp_attempts' => 0,
        ])->save();

        $this->sms->send(
            $user->phone,
            trans('Your Laundo verification code is :code', ['code' => $code])
        );

        return $code;
    }

    /**
     * Check a submitted code.
     *
     * Every failure path returns a distinct reason so the caller can answer
     * usefully, but none of them reveals the correct code or how close a guess was.
     *
     * @return array{ok: bool, reason?: string}
     */
    public function verify(User $user, string $code): array
    {
        if (! $user->otp || ! $user->otp_expires_at) {
            return ['ok' => false, 'reason' => 'no_code'];
        }

        if (now()->greaterThan($user->otp_expires_at)) {
            $this->burn($user);

            return ['ok' => false, 'reason' => 'expired'];
        }

        $max = (int) config('sms.otp.max_attempts', 5);

        if ($user->otp_attempts >= $max) {
            $this->burn($user);

            return ['ok' => false, 'reason' => 'too_many_attempts'];
        }

        if (! Hash::check($code, $user->otp)) {
            // Count the miss before answering, so a burst of parallel guesses
            // still walks the counter up.
            $user->increment('otp_attempts');

            return ['ok' => false, 'reason' => 'invalid'];
        }

        // Single use: clear it the moment it succeeds.
        $this->burn($user);

        return ['ok' => true];
    }

    /**
     * The fixed code stood up while SMS delivery is not integrated.
     *
     * Returns null — and the caller falls back to a random code — unless the
     * configured value is exactly `length` digits, so a typo'd env cannot
     * produce a code the `digits:6` request rules would refuse.
     *
     * @see config('sms.otp.static_code')
     */
    private function staticCode(int $length): ?string
    {
        $code = trim((string) config('sms.otp.static_code', ''));

        if (! preg_match('/^\d{'.$length.'}$/', $code)) {
            return null;
        }

        // Loud on purpose, in the same spirit as the log SMS driver: an install
        // handing out one predictable code for every account is a temporary
        // state, and it should be visible in the log rather than silent.
        Log::warning('[OTP:STATIC-CODE — NOT RANDOM] SMS delivery is not integrated; every code issued is the same fixed value. Set OTP_STATIC_CODE= to restore random codes.');

        return $code;
    }

    /**
     * random_int is cryptographically secure; rand()/mt_rand() are not, and an
     * OTP is a credential.
     */
    private function randomCode(int $length): string
    {
        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }

    /**
     * Discard whatever code is on the account.
     */
    public function burn(User $user): void
    {
        $user->forceFill([
            'otp' => null,
            'otp_expires_at' => null,
            'otp_attempts' => 0,
        ])->save();
    }
}
