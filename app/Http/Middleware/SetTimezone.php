<?php

namespace App\Http\Middleware;

use App\Modules\Country\Models\Country;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Publishes the configured country's timezone for *display*, without touching
 * the timezone the application stores and compares in.
 *
 * This used to call `date_default_timezone_set()` and override
 * `config('app.timezone')`, which broke every timestamp that crossed the request
 * boundary. Concretely: `OtpService` writing an expiry outside a request stored
 * a UTC wall-clock string, and the next request — running on Africa/Cairo —
 * re-read that same string as Cairo local time, i.e. three hours earlier than
 * intended, so codes were born already expired.
 *
 * The same trap was waiting for anything else that writes a timestamp outside
 * the HTTP lifecycle: the queue worker (QUEUE_CONNECTION=database), console
 * commands, seeders, and the recurring-order scheduler planned for P6.
 *
 * So: storage and comparison stay in UTC, always. `app.display_timezone` is what
 * views and formatters convert into — see `displayTimezone()` and `humanDate()`.
 */
class SetTimezone
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next)
    {
        $countryId = getSettingValue('Country_Id');
        $timezone = $countryId ? Country::find($countryId)?->timezone : null;

        if ($timezone) {
            // Display only. `app.timezone` is deliberately left alone.
            config(['app.display_timezone' => $timezone]);
        }

        return $next($request);
    }
}
