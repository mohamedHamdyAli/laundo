<?php

namespace App\Http\Middleware;

use App\Modules\Country\Models\Country;
use Closure;
use Illuminate\Http\Request;

class SetTimezone
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next)
    {
        $countryId = getSettingValue('Country_Id');
        $timezone = $countryId ? Country::find($countryId)?->timezone : null;

        if ($timezone) {
            config(['app.timezone' => $timezone]);
            date_default_timezone_set($timezone);
        }

        return $next($request);
    }
}
