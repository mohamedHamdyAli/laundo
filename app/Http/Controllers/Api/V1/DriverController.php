<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Modules\Driver\Models\Driver;
use App\Services\Auth\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * The driver app's own surface.
 *
 * Deliberately separate from the customer endpoints rather than shared with a
 * role check bolted on: the two apps want different payloads, and keeping them
 * apart means a customer token reaching a driver route is a routing question
 * with one answer, not a conditional someone can get wrong later.
 *
 * There is no registration here. The design's driver login screen offers only
 * «تواصل مع المشرف» — accounts are created in the dashboard.
 */
class DriverController extends Controller
{
    public function __construct(private readonly OtpService $otp) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'regex:'.phoneRegex()],
            'password' => ['required', 'string'],
        ], [
            'phone.regex' => __('Please enter a valid Egyptian phone number.'),
        ]);

        // Queried through Driver, so its role scope means a customer or a laundry
        // owner simply is not found here, whatever their password.
        $driver = Driver::where('phone', $data['phone'])->first();

        if (! $driver || ! Hash::check($data['password'], (string) $driver->password)) {
            return failReturnAuth(__('The phone number or password is incorrect.'));
        }

        // The design shows an «الحساب نشط» badge on this screen, so the state is
        // something the driver is meant to be told about plainly.
        if (! $driver->isActive()) {
            return failReturnForbidden(__('Your account is not active. Please contact your supervisor.'));
        }

        return successReturnData($this->payload($driver, withToken: true), __('Signed in.'));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return returnSuccessMsg(__('Signed out.'));
    }

    public function profile(Request $request): JsonResponse
    {
        return successReturnData($this->payload($this->driver($request)));
    }

    /**
     * Only the fields a driver owns.
     *
     * Vehicle details, documents and zones are absent on purpose: they are
     * verified records and territory assignments, so they are set in the
     * dashboard. A driver editing their own licence expiry would defeat the point
     * of recording it.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $driver = $this->driver($request);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:191'],
            'email' => ['nullable', 'email', 'max:191', 'unique:users,email,'.$driver->id],
            'image_profile' => ['nullable', 'image', 'mimes:jpg,png,jpeg,gif,svg', 'max:2048'],
        ]);

        if (! empty($data['image_profile'])) {
            $data['image_profile'] = uploadOrUpdateImage(
                $data['image_profile'],
                'images/drivers/image',
                $driver->image_profile
            );
        } else {
            unset($data['image_profile']);
        }

        $driver->update($data);

        return successReturnData($this->payload($driver->fresh(['profile', 'zones'])), __('Profile updated.'));
    }

    /**
     * The «متاح لاستقبال المهام» switch.
     *
     * Refused while the account is inactive: letting a suspended driver flip
     * themselves available would put them back in the dispatch pool.
     */
    public function setAvailability(Request $request): JsonResponse
    {
        $data = $request->validate(['is_available' => ['required', 'boolean']]);

        $driver = $this->driver($request);

        if (! $driver->isActive()) {
            return failReturnForbidden(__('Your account is not active. Please contact your supervisor.'));
        }

        $driver->profile()->updateOrCreate(
            ['user_id' => $driver->id],
            ['is_available' => $data['is_available']]
        );

        return successReturnData(
            ['is_available' => (bool) $data['is_available']],
            $data['is_available'] ? __('You are now receiving new tasks.') : __('You have stopped receiving new tasks.')
        );
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $driver = $this->driver($request);

        if (! Hash::check($data['current_password'], (string) $driver->password)) {
            return failReturnValidation(['current_password' => [__('The current password is incorrect.')]]);
        }

        $driver->forceFill(['password' => Hash::make($data['password'])])->save();

        $keep = $request->user()->currentAccessToken()->id;
        $driver->tokens()->where('id', '!=', $keep)->delete();

        return returnSuccessMsg(__('Password changed. Other devices have been signed out.'));
    }

    /**
     * Password reset by OTP, the same path customers use — a driver locked out
     * mid-shift should not have to wait for a supervisor.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'regex:'.phoneRegex()]]);

        $driver = Driver::where('phone', $data['phone'])->first();

        // Answered the same either way, so this cannot be used to discover which
        // numbers belong to drivers.
        if ($driver) {
            $this->otp->issue($driver);
        }

        return returnSuccessMsg(__('If the number is registered, a code has been sent.'));
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'regex:'.phoneRegex()],
            'code' => ['required', 'string', 'digits:'.(int) config('sms.otp.length', 6)],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $driver = Driver::where('phone', $data['phone'])->first();

        if (! $driver) {
            return failReturnNotFound(__('No account found for this phone number.'));
        }

        $result = $this->otp->verify($driver, $data['code']);

        if (! $result['ok']) {
            return match ($result['reason']) {
                'expired' => failReturnValidation(['code' => [__('This code has expired. Please request a new one.')]]),
                'too_many_attempts' => failReturnThrottled(null, __('Too many incorrect attempts. Please request a new code.')),
                'no_code' => failReturnValidation(['code' => [__('No active code. Please request one.')]]),
                default => failReturnValidation(['code' => [__('This code is incorrect.')]]),
            };
        }

        $driver->forceFill(['password' => Hash::make($data['password'])])->save();
        $driver->tokens()->delete();

        return returnSuccessMsg(__('Password updated. Please sign in again.'));
    }

    /**
     * Re-reads the authenticated user through the Driver model.
     *
     * `$request->user()` returns a plain User, so the role scope would not have
     * applied. Going through Driver is what guarantees a customer token cannot
     * operate these endpoints even if it reached them.
     */
    private function driver(Request $request): Driver
    {
        $driver = Driver::with(['profile', 'zones'])->find($request->user()->id);

        abort_unless($driver !== null, 403, 'This endpoint is for drivers.');

        return $driver;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Driver $driver, bool $withToken = false): array
    {
        $profile = $driver->profile;

        $data = [
            'id' => $driver->id,
            'name' => $driver->name,
            'phone' => $driver->phone,
            'email' => $driver->email,
            'image' => getImageassetUrl($driver->image_profile),
            'status' => $driver->status,
            'is_available' => (bool) $profile?->is_available,
            'vehicle' => [
                'type' => $profile?->vehicle_type,
                'plate_number' => $profile?->plate_number,
            ],
            'license' => [
                'number' => $profile?->license_number,
                'expiry' => $profile?->license_expiry?->toDateString(),
            ],
            // «مستندات المركبة» — a row in the driver's account screen that had
            // nothing behind it: the columns have existed since P5 and the
            // payload never returned them, so the screen opened on an empty
            // page. Read-only, like the rest of the verified record — a driver
            // editing their own licence expiry would defeat the point of it.
            'documents' => [
                [
                    'key' => 'license',
                    'label' => __('Driving licence'),
                    'url' => $profile?->license_image ? getImageassetUrl($profile->license_image) : null,
                    'expiry' => $profile?->license_expiry?->toDateString(),
                ],
                [
                    'key' => 'vehicle_registration',
                    'label' => __('Vehicle registration'),
                    'url' => $profile?->vehicle_registration_image
                        ? getImageassetUrl($profile->vehicle_registration_image)
                        : null,
                    'expiry' => $profile?->vehicle_registration_expiry?->toDateString(),
                ],
                [
                    'key' => 'national_id',
                    'label' => __('National ID'),
                    'url' => $profile?->national_id_image ? getImageassetUrl($profile->national_id_image) : null,
                    'expiry' => null,
                ],
            ],
            'shift' => $profile?->shiftLabel(),
            'zones' => $driver->zones->map(fn ($zone) => [
                'id' => $zone->id,
                'name' => getLocalizedValue($zone, 'name'),
            ])->values(),
        ];

        if ($withToken) {
            $data['token'] = $driver->createToken('driver-app')->plainTextToken;
        }

        return $data;
    }
}
