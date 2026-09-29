<?php

namespace App\Providers;

use App\Services\ActivityLogger;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the activity log to every model and to panel sign-ins.
 *
 * Wildcard listeners on the Eloquent events, so no model has to opt in and no
 * screen added later can forget to — see App\Services\ActivityLogger.
 */
class ActivityLogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ActivityLogger::class);
    }

    public function boot(): void
    {
        foreach (['created', 'updated', 'deleted'] as $event) {
            Event::listen("eloquent.{$event}: *", function (string $name, array $payload) use ($event) {
                if (isset($payload[0]) && $payload[0] instanceof Model) {
                    app(ActivityLogger::class)->record($payload[0], $event);
                }
            });
        }

        Event::listen(Login::class, fn (Login $e) => app(ActivityLogger::class)->auth($e->user, 'login'));
        Event::listen(Logout::class, fn (Logout $e) => app(ActivityLogger::class)->auth($e->user, 'logout'));
    }
}
