<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;

class EnsureDashboardRole
{
    /**
     * Role types allowed to reach /admin.
     *
     * `laundry` shares the same prefix as `dashboard` on purpose: CheckPermission
     * and MenuBuilder both derive what a user may see from their role's
     * permissions, so a laundry role with a narrower permission set is confined
     * automatically and neither of them needed changing. What a laundry user may
     * see WITHIN an allowed page is enforced separately by the tenant scope.
     *
     * `app` (customers and drivers) stays locked out — those are API-only.
     */
    private const ALLOWED_TYPES = ['dashboard', 'laundry'];

    public function handle($request, Closure $next)
    {
        $user = Auth::user();

        // لو مش مسجل
        if (! $user || ! $user->role) {
            return redirect()->route('login');
        }

        // لو role مش dashboard أو laundry
        if (! in_array($user->role->type, self::ALLOWED_TYPES, true)) {
            return abort(403, 'Unauthorized action.');
        }

        return $next($request);
    }
}
