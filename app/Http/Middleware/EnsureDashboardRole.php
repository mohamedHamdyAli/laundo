<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;

class EnsureDashboardRole
{
    /**
     * Which role types reach /admin is `User::canReachPanel()`, not a list here.
     *
     * It was a private const on this class, which was fine while this was the
     * only thing asking. The landing page's account bar now asks the same
     * question — it offers a button into the panel — and a second copy of the
     * list is how that button comes to invite somebody into a 403.
     */
    public function handle($request, Closure $next)
    {
        $user = Auth::user();

        // لو مش مسجل
        if (! $user || ! $user->role) {
            return redirect()->route('login');
        }

        // لو role مش dashboard أو laundry
        if (! $user->canReachPanel()) {
            return abort(403, 'Unauthorized action.');
        }

        return $next($request);
    }
}
