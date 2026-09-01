<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * The authenticated customer's own account: read it, edit it, change the
 * password, close it.
 */
class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return successReturnData($this->present($request));
    }

    /**
     * Name, email and photo only.
     *
     * The phone is deliberately not editable here: it is the account identity and
     * changing it needs its own verified flow, not a profile field.
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:191'],
            'email' => ['nullable', 'email', 'max:191', Rule::unique('users', 'email')->ignore($user->id)],
            'gender' => ['nullable', 'in:male,female'],
            'image_profile' => ['nullable', 'image', 'mimes:jpg,png,jpeg,gif,svg', 'max:2048'],
        ]);

        if (array_key_exists('image_profile', $data) && $data['image_profile']) {
            $data['image_profile'] = uploadOrUpdateImage(
                $data['image_profile'],
                'images/customers/image',
                $user->image_profile
            );
        } else {
            unset($data['image_profile']);
        }

        $user->update($data);

        return successReturnData($this->present($request), 'Profile updated.');
    }

    /**
     * Requires the current password, and revokes every other token afterwards:
     * a password change is exactly the moment a session on a lost device should
     * stop working. The token making the request survives so the app stays in.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], (string) $user->password)) {
            return failReturnValidation([
                'current_password' => [trans('The current password is incorrect.')],
            ]);
        }

        DB::transaction(function () use ($user, $data, $request) {
            $user->forceFill(['password' => Hash::make($data['password'])])->save();

            $keep = $request->user()->currentAccessToken()->id;
            $user->tokens()->where('id', '!=', $keep)->delete();
        });

        return returnSuccessMsg('Password changed. Other devices have been signed out.');
    }

    /**
     * Closes the account.
     *
     * Soft delete by decision: orders and invoices stay attached for accounting
     * and disputes. A consequence worth stating — the unique indexes on phone and
     * email still cover trashed rows, so the number cannot be registered again.
     */
    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();

        DB::transaction(function () use ($user) {
            $user->tokens()->delete();
            $user->delete();
        });

        return returnSuccessMsg('Your account has been closed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'email' => $user->email,
            'gender' => $user->gender,
            'image' => getImageassetUrl($user->image_profile),
            'phone_verified' => $user->hasVerifiedPhone(),
            'addresses_count' => $user->addresses()->count(),
        ];
    }
}
