<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Modules\Driver\Enums\VehicleType;
use App\Modules\Driver\Models\Driver;
use App\Modules\Driver\Models\DriverProfile;
use App\Modules\Order\Enums\TaskStatus;
use App\Services\Auth\OtpService;
use App\Services\Auth\PasswordResetTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

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
    public function __construct(
        private readonly OtpService $otp,
        private readonly PasswordResetTicket $tickets,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'regex:'.phoneRegex()],
            'password' => ['required', 'string'],
        ], [
            'phone.regex' => __('Enter the number with its country code, e.g. +201012345678.'),
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
     * The driver's own record — «بيانات المركبة», «رخصة القيادة», «مستندات المركبة».
     *
     * Vehicle, licence and documents used to be refused here, on the reasoning
     * that a driver editing their own licence expiry defeats the point of
     * recording it. The owner's decision reverses that, and the practical case is
     * the stronger one: these are the things only the driver has — their car,
     * their papers — and the alternative was an operator typing a plate number
     * off a photograph somebody sent on WhatsApp. The expiry was never a gate
     * anyway: `expiredDocuments()` surfaces a lapse for a human and, by the
     * decision recorded there, does not stop assignment by itself.
     *
     * **Zones are still refused.** Territory decides who is handed work, so a
     * driver choosing their own would let them keep the short trips and drop the
     * rest. That is dispatch, not a preference.
     *
     * **Everything is optional, and every save is partial.** The design has three
     * screens with three save buttons, and each posts only what it owns —
     * `sometimes` means an absent field is left alone rather than cleared, so
     * saving the licence screen cannot blank the vehicle beside it.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $driver = $this->driver($request);

        $image = ['nullable', 'image', 'mimes:jpg,png,jpeg,gif,svg', 'max:2048'];

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:191'],
            'email' => ['nullable', 'email', 'max:191', 'unique:users,email,'.$driver->id],
            'image_profile' => $image,

            // بيانات المركبة
            'vehicle_type' => ['sometimes', 'nullable', Rule::in(VehicleType::values())],
            'plate_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'vehicle_brand' => ['sometimes', 'nullable', 'string', 'max:100'],
            'vehicle_model' => ['sometimes', 'nullable', 'string', 'max:100'],
            'vehicle_year' => ['sometimes', 'nullable', 'string', 'max:10'],
            'vehicle_color' => ['sometimes', 'nullable', 'string', 'max:50'],

            // رخصة القيادة
            'license_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'license_type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'license_issued_at' => ['sometimes', 'nullable', 'date'],
            'license_expiry' => ['sometimes', 'nullable', 'date'],
            'license_image' => $image,

            // مستندات المركبة
            'vehicle_registration_image' => $image,
            'vehicle_registration_expiry' => ['sometimes', 'nullable', 'date'],
            'vehicle_insurance_image' => $image,
            'vehicle_insurance_expiry' => ['sometimes', 'nullable', 'date'],
            'vehicle_inspection_image' => $image,
            'vehicle_inspection_expiry' => ['sometimes', 'nullable', 'date'],
            'national_id_image' => $image,
            'other_document_image' => $image,
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

        $profile = $this->profilePayload($request, $driver);

        DB::transaction(function () use ($driver, $data, $profile) {
            $driver->update(array_intersect_key($data, array_flip(['name', 'email', 'image_profile'])));

            if ($profile !== []) {
                // updateOrCreate rather than update: a driver whose profile row
                // never existed would otherwise save into nothing and be told it
                // had worked.
                $driver->profile()->updateOrCreate(['user_id' => $driver->id], $profile);
            }
        });

        return successReturnData($this->payload($driver->fresh(['profile', 'zones'])), __('Profile updated.'));
    }

    /**
     * What of this request belongs on `driver_profiles`.
     *
     * Assembled key by key from what was actually sent, and that is the whole of
     * the partial-update behaviour: `has()` is true for a field posted empty —
     * the driver clearing a plate number — and false for one the screen never
     * drew, so a licence save cannot blank the vehicle beside it.
     *
     * The six file fields are handled apart, because a file is only a change when
     * one was uploaded. `uploadOrUpdateImage()` hands back the existing path when
     * given null, and an absent file has to leave the stored document alone.
     *
     * @return array<string, mixed>
     */
    private function profilePayload(Request $request, Driver $driver): array
    {
        $payload = [];

        $scalars = [
            'vehicle_type', 'plate_number', 'vehicle_brand', 'vehicle_model',
            'vehicle_year', 'vehicle_color',
            'license_number', 'license_type', 'license_issued_at', 'license_expiry',
            'vehicle_registration_expiry', 'vehicle_insurance_expiry',
            'vehicle_inspection_expiry',
        ];

        foreach ($scalars as $field) {
            if ($request->has($field)) {
                $payload[$field] = $request->input($field);
            }
        }

        $documents = [
            'license_image', 'vehicle_registration_image', 'vehicle_insurance_image',
            'vehicle_inspection_image', 'national_id_image', 'other_document_image',
        ];

        foreach ($documents as $field) {
            if (! $request->hasFile($field)) {
                continue;
            }

            $payload[$field] = uploadOrUpdateImage(
                $request->file($field),
                'images/drivers/documents',
                $driver->profile?->{$field}
            );
        }

        return $payload;
    }

    /**
     * «تتبع المندوب مباشرة» — the driver's phone reporting where it is.
     *
     * Three rules, and each is a decision rather than a detail:
     *
     * **Only while they are carrying something.** A driver between jobs, or on
     * their own time with the app open, is not tracked — the write is refused and
     * the response says `tracking: false` so the app stops asking. Following
     * somebody who is not working is not a feature of a laundry.
     *
     * **The last point only.** No trail. The design draws one moving dot, and a
     * points table would be the largest in the system inside a month.
     *
     * **Never fillable.** Written with forceFill after the check above, so no
     * profile update can move a driver on the map.
     */
    public function reportLocation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $driver = $this->driver($request);

        if (! $this->hasLiveTask($driver)) {
            return successReturnData(
                ['tracking' => false],
                __('You have no journey in progress.')
            );
        }

        $profile = $driver->profile;

        if (! $profile) {
            return successReturnData(['tracking' => false]);
        }

        $profile->forceFill([
            'last_lat' => $data['lat'],
            'last_lng' => $data['lng'],
            'located_at' => now(),
        ])->save();

        return successReturnData(['tracking' => true]);
    }

    /**
     * A leg somebody has been given and has not finished.
     */
    private function hasLiveTask(Driver $driver): bool
    {
        return $driver->tasks()
            ->whereIn('status', [TaskStatus::Assigned->value, TaskStatus::Started->value])
            ->exists();
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

    /**
     * The middle step: check the code, hand back a ticket.
     *
     * Split out of `resetPassword` to match the customer flow, which was split
     * for a reason that applies here just as much — the app's verify screen was
     * checking nothing and carrying the six digits forward to be re-sent with
     * the password. Two different shapes for one operation was also two
     * contracts for the driver app and the customer app to get right.
     */
    public function verifyResetCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'regex:'.phoneRegex()],
            'code' => ['required', 'string', 'digits:'.(int) config('sms.otp.length', 6)],
        ], [
            'phone.regex' => __('Enter the number with its country code, e.g. +201012345678.'),
        ]);

        $driver = Driver::where('phone', $data['phone'])->first();

        if (! $driver) {
            return failReturnNotFound(__('No account found for this phone number.'));
        }

        $result = $this->otp->verify($driver, $data['code']);

        if (! $result['ok']) {
            return $this->otpFailure($result['reason'] ?? 'invalid');
        }

        $ticket = $this->tickets->issue($driver);

        return successReturnData([
            'reset_token' => $ticket['token'],
            'expires_in' => $ticket['expires_in'],
        ], __('Code confirmed.'));
    }

    /**
     * The last step: spend the ticket and set the password.
     *
     * No phone and no code — the ticket carries the identity, and the code was
     * consumed by `verifyResetCode`, which is what stops it being replayed here.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reset_token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $holder = $this->tickets->holder($data['reset_token']);

        // Re-read through Driver, not the holder as returned: the model's role
        // scope is what makes a customer's ticket useless on this endpoint.
        $driver = $holder ? Driver::whereKey($holder->getKey())->first() : null;

        if (! $driver) {
            $message = __('This password reset is no longer valid. Please request a new code.');

            return failReturnValidation(['reset_token' => [$message]], $message);
        }

        $driver->forceFill([
            'password' => Hash::make($data['password']),
            'password_reset_token' => null,
            'password_reset_token_expires_at' => null,
        ])->save();

        $driver->tokens()->delete();

        return returnSuccessMsg(__('Password updated. Please sign in again.'));
    }

    /**
     * The OTP refusals, answered the same way on both driver code endpoints.
     */
    private function otpFailure(string $reason): JsonResponse
    {
        return match ($reason) {
            'expired' => failReturnValidation(['code' => [__('This code has expired. Please request a new one.')]]),
            'too_many_attempts' => failReturnThrottled(null, __('Too many incorrect attempts. Please request a new code.')),
            'no_code' => failReturnValidation(['code' => [__('No active code. Please request one.')]]),
            default => failReturnValidation(['code' => [__('This code is incorrect.')]]),
        };
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
     * «مستندات المركبة» — the six slots, in the order the screen draws them.
     *
     * Always all six, whether or not anything has been uploaded: the screen shows
     * a row per document so the driver can add the missing ones, and a payload
     * that listed only what exists would leave them nothing to tap.
     *
     * `field` is the name to post the file back under, so the app does not keep
     * its own mapping from a display key to a form field — the two drifting apart
     * is how an upload silently lands on the wrong document.
     *
     * `required` marks the three the business actually wants; it is a hint for
     * the screen, never enforced. Nothing here gates having an account: operations
     * onboards a courier on the phone and photographs the papers afterwards.
     *
     * @return array<int, array<string, mixed>>
     */
    private function documents(?DriverProfile $profile): array
    {
        $slots = [
            ['license', 'Driving licence', 'license_image', 'license_expiry', true],
            ['vehicle_registration', 'Vehicle registration', 'vehicle_registration_image', 'vehicle_registration_expiry', true],
            ['vehicle_insurance', 'Vehicle insurance', 'vehicle_insurance_image', 'vehicle_insurance_expiry', true],
            ['vehicle_inspection', 'Technical inspection', 'vehicle_inspection_image', 'vehicle_inspection_expiry', false],
            ['national_id', 'National ID', 'national_id_image', null, true],
            ['other', 'Other documents', 'other_document_image', null, false],
        ];

        $documents = [];

        foreach ($slots as [$key, $label, $field, $expiryField, $required]) {
            $path = $profile?->{$field};
            $expiry = $expiryField ? $profile?->{$expiryField} : null;

            $documents[] = [
                'key' => $key,
                'label' => __($label),
                'field' => $field,
                'url' => $path ? getImageassetUrl($path) : null,
                'uploaded' => $path !== null,
                'expiry' => $expiry?->toDateString(),
                // Lapsed, rather than merely dated: the screen paints this red,
                // and it is worked out here so the two apps cannot disagree with
                // the dashboard about what «expired» means.
                'is_expired' => $expiry !== null && $expiry->startOfDay()->isPast(),
                'required' => $required,
            ];
        }

        return $documents;
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
                // The stored string, and its label for display. The apps
                // were printing the raw column, which is now a slug.
                'type' => $profile?->vehicle_type,
                'type_label' => VehicleType::parse($profile?->vehicle_type)?->label(),
                'plate_number' => $profile?->plate_number,
                'brand' => $profile?->vehicle_brand,
                'model' => $profile?->vehicle_model,
                'year' => $profile?->vehicle_year,
                'color' => $profile?->vehicle_color,
            ],
            'license' => [
                'number' => $profile?->license_number,
                'type' => $profile?->license_type,
                'issued_at' => $profile?->license_issued_at?->toDateString(),
                'expiry' => $profile?->license_expiry?->toDateString(),
                'image' => $profile?->license_image ? getImageassetUrl($profile->license_image) : null,
            ],
            // «مستندات المركبة» — a row in the driver's account screen that had
            // nothing behind it: the columns have existed since P5 and the
            // payload never returned them, so the screen opened on an empty
            // page. Read-only, like the rest of the verified record — a driver
            // editing their own licence expiry would defeat the point of it.
            // **Every slot is always listed, filled or not.** The screen draws a
            // row per document with «لم يتم الرفع» beside the empty ones, so a
            // list that omitted what has not been collected would give the driver
            // no way to upload it. `field` is the key to post it back under.
            'documents' => $this->documents($profile),
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
