<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Http\Requests\Api\V1\ResetPasswordRequest;
use App\Http\Requests\Api\V1\VerifyOtpRequest;
use App\Http\Requests\Api\V1\VerifyResetCodeRequest;
use App\Modules\User\Models\User;
use App\Services\Auth\CustomerAuthService;
use App\Services\Auth\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer authentication.
 *
 * Verification codes are never returned in a response, on any environment: the
 * log driver is how they are read during development.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly CustomerAuthService $auth,
        private readonly OtpService $otp,
    ) {}

    /**
     * Create the account and send the first code. The account cannot sign in
     * until the code is confirmed.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $this->auth->register($request->validated());

        return successReturnCreated(
            ['phone' => $request->input('phone')],
            'A verification code has been sent to your phone.'
        );
    }

    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $user = User::where('phone', $request->input('phone'))->first();

        if (! $user) {
            return failReturnNotFound('No account found for this phone number.');
        }

        $result = $this->auth->verifyPhone($user, $request->input('code'));

        if (! $result['ok']) {
            return $this->otpFailure($result['reason'] ?? 'invalid');
        }

        return successReturnData($this->authPayload($user), 'Phone verified.');
    }

    /**
     * Issue a fresh code. Guarded by the `otp` limiter, which is keyed on the
     * phone number as well as the IP so one number cannot be used to pump SMS
     * from rotating addresses.
     */
    public function resendOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'regex:'.phoneRegex()],
        ]);

        $user = User::where('phone', $data['phone'])->first();

        // Answered the same way whether or not the number exists, so this cannot
        // be used to discover which numbers are registered.
        if ($user && $this->auth->isCustomer($user)) {
            $this->otp->issue($user);
        }

        return returnSuccessMsg('If the number is registered, a code has been sent.');
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->auth->login($request->input('phone'), $request->input('password'));

        if (! $result['ok']) {
            return match ($result['reason']) {
                // Re-issue a code so an unverified user has a way forward rather
                // than a dead end.
                'phone_not_verified' => $this->onUnverified($result['user']),
                'account_inactive' => failReturnForbidden('This account is not active. Please contact support.'),
                default => failReturnAuth('The phone number or password is incorrect.'),
            };
        }

        return successReturnData($this->authPayload($result['user']), 'Signed in.');
    }

    /**
     * Revokes only the token that made the request, so signing out on one device
     * leaves the others alone.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return returnSuccessMsg('Signed out.');
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'regex:'.phoneRegex()],
        ]);

        $this->auth->startPasswordReset($data['phone']);

        return returnSuccessMsg('If the number is registered, a code has been sent.');
    }

    /**
     * The middle step: check the code and hand back a ticket for the password
     * step.
     *
     * Split out of `resetPassword`, which used to take the phone, the code and
     * the new password in one call — so the app's «verify» screen verified
     * nothing and had to carry the six digits forward to be sent again. A wrong
     * code is now answered on the screen that asked for it.
     *
     * The ticket is not an access token. It authorises exactly one thing, once.
     */
    public function verifyResetCode(VerifyResetCodeRequest $request): JsonResponse
    {
        $user = User::where('phone', $request->input('phone'))->first();

        if (! $user || ! $this->auth->isCustomer($user)) {
            return failReturnNotFound(__('No account found for this phone number.'));
        }

        $result = $this->auth->verifyResetCode($user, $request->input('code'));

        if (! $result['ok']) {
            return $this->otpFailure($result['reason'] ?? 'invalid');
        }

        return successReturnData([
            'reset_token' => $result['token'],
            // Seconds, so the app can show its own countdown on the password
            // screen rather than discovering the ticket died on submit.
            'expires_in' => $result['expires_in'],
        ], __('Code confirmed.'));
    }

    /**
     * The last step: spend the ticket and set the password.
     *
     * No phone and no code — the ticket carries the identity, and the code was
     * consumed by `verifyResetCode`, which is what stops it being replayed here.
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $result = $this->auth->completePasswordReset(
            $request->input('reset_token'),
            $request->input('password')
        );

        if (! $result['ok']) {
            // One answer whether the ticket never existed, belongs to a staff
            // account or has expired: none of that is the caller's business,
            // and the way forward is the same in all three cases.
            // The message is passed as the envelope's `msg` as well as into
            // `errors`, so this reads the same as a request-validation failure
            // on the same endpoint — those come through `failedValidation()`,
            // which promotes the first error into `msg`, and leaving this one
            // generic meant a bad token and a malformed one answered
            // differently in the field the app is most likely to show.
            $message = __('This password reset is no longer valid. Please request a new code.');

            return failReturnValidation(['reset_token' => [$message]], $message);
        }

        return returnSuccessMsg(__('Password updated. Please sign in again.'));
    }

    /**
     * Issue a token and describe the account. The token is a Sanctum personal
     * access token; a customer may hold several, one per device.
     *
     * @return array<string, mixed>
     */
    private function authPayload(User $user): array
    {
        return [
            'token' => $user->createToken('mobile')->plainTextToken,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'email' => $user->email,
                'image' => getImageassetUrl($user->image_profile),
                'phone_verified' => $user->hasVerifiedPhone(),
            ],
        ];
    }

    private function onUnverified(User $user): JsonResponse
    {
        $this->otp->issue($user);

        return failReturnForbidden('Your phone is not verified yet. A new code has been sent.');
    }

    private function otpFailure(string $reason): JsonResponse
    {
        return match ($reason) {
            'expired' => failReturnValidation(
                ['code' => [trans('This code has expired. Please request a new one.')]]
            ),
            'too_many_attempts' => failReturnThrottled(
                null,
                'Too many incorrect attempts. Please request a new code.'
            ),
            'no_code' => failReturnValidation(
                ['code' => [trans('No active code. Please request one.')]]
            ),
            'already_verified' => failReturnMsg('This phone is already verified.'),
            default => failReturnValidation(
                ['code' => [trans('This code is incorrect.')]]
            ),
        };
    }
}
