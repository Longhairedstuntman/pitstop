<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Webhook routes deliberately sit outside the `web` group so they
            // get no session and no CSRF check — a mail provider can present
            // neither. They are secured by VerifyInboundWebhook instead.
            Route::group([], __DIR__.'/../routes/webhooks.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Railway (and any other platform fronting the app with a proxy)
        // terminates TLS at the edge and forwards plain HTTP inward. Without
        // trusting those headers Laravel generates http:// URLs, secure
        // cookies are never set, and the signed-URL / redirect flows break.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_AWS_ELB,
        );

        $middleware->alias([
            'ops' => \App\Http\Middleware\EnsureOps::class,
            'admin' => \App\Http\Middleware\EnsureAdmin::class,
            'inbound.webhook' => \App\Http\Middleware\VerifyInboundWebhook::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
