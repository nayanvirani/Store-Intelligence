<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\AuthenticateShopify;
use App\Http\Middleware\EnsureStorePermission;
use App\Http\Middleware\RequirePlan;
use App\Http\Middleware\VerifyShopifyWebhook;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->prepend(AssignRequestId::class);
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        $middleware->alias([
            'shopify.auth' => AuthenticateShopify::class,
            'shopify.webhook' => VerifyShopifyWebhook::class,
            'store.can' => EnsureStorePermission::class,
            'store.plan' => RequirePlan::class,
            'spa' => \App\Http\Middleware\SpaResponses::class,
            'admin.can' => \App\Http\Middleware\AdminCan::class,
        ]);

        // Embedded requests authenticate with App Bridge session tokens (third-party
        // iframe cookies are unreliable); webhooks authenticate with HMAC.
        $middleware->validateCsrfTokens(except: ['app/*', 'app', 'webhooks/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
