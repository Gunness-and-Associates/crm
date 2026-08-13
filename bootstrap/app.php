<?php

use App\Exceptions\Api\ApiExceptionRenderer;
use App\Http\Middleware\Api\AuthenticateApiToken;
use App\Http\Middleware\Api\RequireScope;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'scope' => RequireScope::class,
        ]);

        // ApiThrottle extends Laravel's own ThrottleRequests, and SortedMiddleware's
        // priority matching walks class_parents() — so it inherits ThrottleRequests'
        // spot in the default $middlewarePriority list and gets silently hoisted
        // ahead of AuthenticateApiToken (which has no priority entry), even though
        // routes/api.php lists AuthenticateApiToken first. That left the rate
        // limiter reading the request before AuthenticateApiToken had set the
        // `oauth_client` attribute it depends on. Force the order explicitly.
        $middleware->prependToPriorityList(
            before: ThrottleRequests::class,
            prepend: AuthenticateApiToken::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Every /api/v1/* error is RFC 7807 (docs/contracts/api-contract.md §1.5) —
        // never the framework's default JSON error shape or an HTML error page.
        $exceptions->renderable(function (Throwable $e, Request $request) {
            if (! $request->is('api/v1/*')) {
                return null;
            }

            return app(ApiExceptionRenderer::class)->render($e);
        });
    })->create();
