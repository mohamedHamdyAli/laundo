<?php

namespace App\Services\Auth;

use App\Modules\User\Models\User;

/**
 * The single-use ticket that stands between «the code checks out» and «here is
 * the new password».
 *
 * Extracted rather than duplicated. Customers and drivers run the same reset —
 * a code to the phone, then a password — and the parts that are easy to get
 * subtly wrong are the parts they share: the digest, the expiry, and clearing
 * the ticket the moment it is spent. Two copies of that is two chances for one
 * of them to keep working after it should not.
 *
 * What is *not* here is who may reset: the caller checks that, because a
 * customer endpoint must refuse a driver's ticket and the other way round. The
 * ticket knows it is valid; it does not know who is allowed to hold one.
 */
class PasswordResetTicket
{
    /**
     * Mint a ticket for this account, replacing any it already had.
     *
     * @return array{token: string, expires_in: int}
     */
    public function issue(User $user): array
    {
        $ttl = (int) config('sms.password_reset_token.ttl_seconds', 600);
        $token = bin2hex(random_bytes(32));

        $user->forceFill([
            // SHA-256, not bcrypt: the password step arrives with the token and
            // nothing else, so this column has to be searchable. Safe for 32
            // random bytes in a way it would not be for a six-digit code, which
            // is why the OTP beside it keeps its bcrypt hash.
            'password_reset_token' => hash('sha256', $token),
            'password_reset_token_expires_at' => now()->addSeconds($ttl),
        ])->save();

        return ['token' => $token, 'expires_in' => $ttl];
    }

    /**
     * The account whose live ticket this is, or null.
     *
     * An expired ticket is burned on the way out rather than left to be
     * presented again and rejected again.
     */
    public function holder(string $token): ?User
    {
        $user = User::where('password_reset_token', hash('sha256', $token))->first();

        if (! $user) {
            return null;
        }

        if (! $user->password_reset_token_expires_at || now()->greaterThan($user->password_reset_token_expires_at)) {
            $this->burn($user);

            return null;
        }

        return $user;
    }

    /**
     * Discard whatever ticket is on the account.
     */
    public function burn(User $user): void
    {
        $user->forceFill([
            'password_reset_token' => null,
            'password_reset_token_expires_at' => null,
        ])->save();
    }
}
