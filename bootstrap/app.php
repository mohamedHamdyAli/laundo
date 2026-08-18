<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\SetLocale::class,
            \App\Http\Middleware\SetTimezone::class,
        ]);
        $middleware->alias([
            'auth' => \App\Http\Middleware\Authenticate::class,
            'permission' => \App\Http\Middleware\CheckPermission::class,
            'dashboard.only' => \App\Http\Middleware\EnsureDashboardRole::class,

        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {})->create();
