<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next)
    {
        // Was `if (Session::has('language'))` and nothing otherwise, so a
        // request with no session kept `config('app.locale')` and the
        // configured default language was never consulted — the panel ignored
        // the setting whose whole job is to be the default.
        app()->setLocale(panelLanguageCode());

        return $next($request);
    }
}
