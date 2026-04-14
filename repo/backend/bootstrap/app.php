<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'auth.signed'     => \App\Http\Middleware\VerifySignedSession::class,
            'mfa.required'    => \App\Http\Middleware\RequireMfa::class,
            'role'            => \App\Http\Middleware\CheckRole::class,
            'permission'      => \App\Http\Middleware\CheckPermission::class,
            'throttle.login'  => \App\Http\Middleware\LoginThrottle::class,
            'log.operation'   => \App\Http\Middleware\LogOperation::class,
            'content.permission' => \App\Http\Middleware\CheckContentPermission::class,
        ]);

        $middleware->api([
            \App\Http\Middleware\CorrelationId::class,
            \App\Http\Middleware\ForceJsonResponse::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->create();
