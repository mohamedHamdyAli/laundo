<?php

namespace App\Services\Auth;

use App\Models\Role;
use App\Modules\Coupon\Services\ReferralService;
use App\Modules\User\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Registration, verification and sign-in for customers.
 *
 * Kept out of the controller so the rules are testable and stated once. Two of
 * them are business decisions rather than conventions:
 *
 *   - A taken phone number is refused outright, verified or not. This blocks
 *     account takeover through re-registration; the trade-off, noted at the time
 *     the decision was made, is that an unverified registration can park on
 *     someone else's number.
 *   - Sign-in requires a verified phone AND an active account. Either missing is
 *     a refusal, and the two are reported separately so the app can say something
 *     useful.
 */
class CustomerAuthService
{
    public function __construct(private readonly OtpService $otp) {}

    /**
     * Create an unverified customer and send the first code.
     *
     * @param  array<string, mixed>  $data
     * @return array{user: User, code: string}
     */
    public function register(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $role = Role::where('slug', Role::USER)->firstOrFail();

            $user = User::create([
                'name' => $data['name'],
                'phone' => $data['phone'],
                'email' => $data['email'] ?? null,
                'password' => $data['password'],
                'role_id' => $role->id,
                'status' => 'active',
                // Explicitly unverified: the OTP step sets this.
                'phone_verified_at' => null,
            ]);

            // Recorded now, paid later — the reward waits for their first paid
            // order, which is what stops the programme being farmed with a
            // handful of phone numbers.
            app(ReferralService::class)->link($user, $data['referral_code'] ?? null);

            return ['user' => $user, 'code' => $this->otp->issue($user)];
        });
    }

    /**
     * Confirm a registration code and mark the phone verified.
     *
     * @return array{ok: bool, reason?: string, user?: User}
     */
    public function verifyPhone(User $user, string $code): array
    {
        if ($user->hasVerifiedPhone()) {
            return ['ok' => false, 'reason' => 'already_verified'];
        }

        $result = $this->otp->verify($user, $code);

        if (! $result['ok']) {
            return $result;
        }

        $user->forceFill(['phone_verified_at' => now()])->save();

        return ['ok' => true, 'user' => $user];
    }

    /**
     * @return array{ok: bool, reason?: string, user?: User}
     */
    public function login(string $phone, string $password): array
    {
        $user = User::where('phone', $phone)->first();

        // One generic answer for "no such account" and "wrong password", so the
        // endpoint cannot be used to enumerate which numbers are registered.
        if (! $user || ! Hash::check($password, (string) $user->password)) {
            return ['ok' => false, 'reason' => 'invalid_credentials'];
        }

        if (! $this->isCustomer($user)) {
            return ['ok' => false, 'reason' => 'invalid_credentials'];
        }

        if (! $user->hasVerifiedPhone()) {
            return ['ok' => false, 'reason' => 'phone_not_verified', 'user' => $user];
        }

        if (! $user->isActive()) {
            return ['ok' => false, 'reason' => 'account_inactive'];
        }

        return ['ok' => true, 'user' => $user];
    }

    /**
     * Start a password reset by sending a code to the phone.
     *
     * @return array{ok: bool, reason?: string, user?: User}
     */
    public function startPasswordReset(string $phone): array
    {
        $user = User::where('phone', $phone)->first();

        if (! $user || ! $this->isCustomer($user)) {
            return ['ok' => false, 'reason' => 'not_found'];
        }

        $this->otp->issue($user);

        return ['ok' => true, 'user' => $user];
    }

    /**
     * Finish a password reset. Every existing token is revoked, because a
     * password change is exactly when a compromised session must be cut off.
     *
     * @return array{ok: bool, reason?: string}
     */
    public function completePasswordReset(User $user, string $code, string $password): array
    {
        $result = $this->otp->verify($user, $code);

        if (! $result['ok']) {
            return $result;
        }

        $user->forceFill([
            'password' => Hash::make($password),
            // A reset also proves possession of the number.
            'phone_verified_at' => $user->phone_verified_at ?? now(),
        ])->save();

        $user->tokens()->delete();

        return ['ok' => true];
    }

    /**
     * Staff accounts live in the same table, so the customer endpoints have to
     * exclude them explicitly.
     */
    public function isCustomer(User $user): bool
    {
        return $user->role?->slug === Role::USER;
    }
}
