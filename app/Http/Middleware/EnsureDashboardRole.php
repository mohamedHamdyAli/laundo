<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;

class EnsureDashboardRole
{
    public function handle($request, Closure $next)
    {
        $user = Auth::user();

        // لو مش مسجل
        if (!$user || !$user->role) {
            return redirect()->route('login');
        }

        // لو role مش dashboard
        if ($user->role->type !== 'dashboard') {
            return abort(403, 'Unauthorized action.');
        }

        return $next($request);
    }
}
