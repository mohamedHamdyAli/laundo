<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Sets the application locale for stateless API requests.
 *
 * The dashboard uses SetLocale, which reads the session. API clients have no
 * session, so they send a `lang` header instead. getCurrentLocale() already
 * reads that header, validates it against the languages table and falls back
 * to the default language — this middleware only pushes the result into the
 * application so trans()/__() localize API responses too.
 */
class ApiLocale
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next)
    {
        try {
            app()->setLocale(getCurrentLocale());
        } catch (Throwable) {
            // getDefaultLanguage() throws when the languages table has no default
            // row. A missing seed must not turn every API call into a 500, so we
            // fall back to the framework locale and carry on.
        }

        return $next($request);
    }
}
