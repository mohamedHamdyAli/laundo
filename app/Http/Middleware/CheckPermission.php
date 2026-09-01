<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;

class CheckPermission
{
    public function handle($request, Closure $next, string $permission)
    {

        if (! $permission) {
            return $next($request);
        }
        $user = Auth::user();

        if (! $user || ! $user->role) {
            return abort(403, 'Unauthorized');
        }

        // Super Admin bypass
        if ($user->role->slug === 'super_admin') {
            return $next($request);
        }

        if (! $user->role->permissions->contains('slug', $permission)) {
            return abort(403, 'Unauthorized');
        }

        return $next($request);
    }
}
