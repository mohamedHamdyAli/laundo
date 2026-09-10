<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Login Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles authenticating users for the application and
    | redirecting them to your home screen. The controller uses a trait
    | to conveniently provide its functionality to your applications.
    |
    */

    use AuthenticatesUsers;

    /**
     * Where to redirect users after login.
     *
     * @var string
     */
    protected $redirectTo = '/admin/home';

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('guest')->except('logout');
        $this->middleware('auth')->only('logout');
    }

    /**
     * Only an active account may sign in.
     *
     * The panel did not check this at all: `AuthenticatesUsers` matches on the
     * email and the password and nothing else, so a suspended moderator, a
     * deactivated laundry's staff and — once registration existed — a laundry
     * still waiting to be approved all signed straight in. The API has always
     * refused an inactive account (`account_inactive`, 403); the panel was the
     * outlier, and `laundryCrudService::deleteRecord()` already promised the
     * opposite, deactivating a deleted laundry's users "so an orphan cannot
     * still sign in".
     *
     * Folded into the credentials rather than checked after authentication, so
     * no session is ever created for an account that may not have one.
     *
     * @return array<string, mixed>
     */
    protected function credentials(Request $request): array
    {
        return $request->only($this->username(), 'password') + ['status' => 'active'];
    }

    /**
     * Why the sign-in failed.
     *
     * A laundry still waiting to be approved is told so. Everyone else gets the
     * generic message: "these credentials do not match" is what stops a stranger
     * using the login form to find out which addresses hold accounts. An
     * application this person filed themselves is not a secret from them, and
     * telling them their password was wrong would send them off to reset a
     * password that works.
     */
    protected function sendFailedLoginResponse(Request $request)
    {
        $pending = User::where('email', $request->input($this->username()))
            ->where('status', 'inactive')
            ->whereHas('laundry', fn ($query) => $query->whereNull('approved_at'))
            ->exists();

        throw ValidationException::withMessages([
            $this->username() => [
                $pending
                    ? __('Your laundry is still being reviewed. We will email you as soon as it is approved.')
                    : trans('auth.failed'),
            ],
        ]);
    }
}
