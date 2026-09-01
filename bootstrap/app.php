<?php

use App\Console\Commands\PruneOldRecords;
use App\Http\Middleware\ApiLocale;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnsureDashboardRole;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetTimezone;
use App\Modules\Notification\Console\AlertSilentPriceConfirmations;
use App\Modules\Notification\Console\AlertStuckTasks;
use App\Modules\Order\Console\DispatchQueuedTasks;
use App\Modules\Order\Console\PromptRecurringOrders;
use App\Modules\Report\Console\SendWeeklyReports;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Auto-discovery only scans app/Console/Commands, and this project's commands
    // live with their module. Registered explicitly rather than moved, so the
    // recurrence code stays in one place.
    ->withCommands([
        PromptRecurringOrders::class,
        DispatchQueuedTasks::class,
        AlertStuckTasks::class,
        AlertSilentPriceConfirmations::class,
        SendWeeklyReports::class,
        // This one does live in app/Console/Commands and so would be discovered
        // anyway. Listed with the others because a schedule that references a
        // command registered somewhere else is the kind of thing that breaks
        // silently when somebody tidies this list.
        PruneOldRecords::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            SetLocale::class,
            SetTimezone::class,
        ]);

        // API requests are stateless: no session, so SetLocale cannot apply.
        // ApiLocale reads the `lang` header instead.
        $middleware->api(append: [
            ApiLocale::class,
            SetTimezone::class,
        ]);

        // Laravel 11+ leaves the api group unthrottled by default.
        // The `api` limiter is defined in AppServiceProvider.
        $middleware->throttleApi();

        $middleware->alias([
            'auth' => Authenticate::class,
            'permission' => CheckPermission::class,
            'dashboard.only' => EnsureDashboardRole::class,

        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Everything under api/* answers in the ApiResponse envelope, never HTML.
        // The dashboard keeps Laravel's default handling untouched.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return match (true) {
                $e instanceof ValidationException => failReturnValidation(
                    $e->errors(),
                    $e->getMessage()
                ),

                $e instanceof AuthenticationException => failReturnAuth(),

                $e instanceof AuthorizationException,
                $e instanceof AccessDeniedHttpException => failReturnForbidden($e->getMessage()),

                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => failReturnNotFound(),

                $e instanceof TooManyRequestsHttpException => failReturnThrottled(
                    (int) ($e->getHeaders()['Retry-After'] ?? 0) ?: null
                ),

                // Any other exception that names its own HTTP status — which is
                // what abort(403) and abort(400) produce. Without this arm they
                // fell through to the 500 below, so a deliberate abort() inside
                // an API controller was reported as a server error.
                $e instanceof HttpExceptionInterface => match ($e->getStatusCode()) {
                    400 => failReturnMsg($e->getMessage() ?: 'Bad request.'),
                    401 => failReturnAuth($e->getMessage()),
                    403 => failReturnForbidden($e->getMessage()),
                    404 => failReturnNotFound($e->getMessage()),
                    422 => failReturnValidation([], $e->getMessage()),
                    429 => failReturnThrottled(),
                    // A 5xx really is a server error; anything else keeps its own
                    // status rather than being flattened.
                    default => $e->getStatusCode() >= 500
                        ? failReturnServer($e->getMessage(), $e)
                        : failReturnMsg($e->getMessage() ?: 'Request failed.'),
                },

                default => failReturnServer('Error Occurred', $e),
            };
        });
    })->create();
