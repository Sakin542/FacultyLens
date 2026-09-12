<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        // STEP 43: the `api` limiter (120/min per user) was defined in AppServiceProvider but never attached —
        // Laravel 12 only adds `throttle:api` to the api group when throttleApi() is called (measured: 130 GETs → 130×200).
        $middleware->throttleApi();
        $middleware->append(\App\Http\Middleware\SecurityHeadersMiddleware::class);
        $middleware->api(prepend: [\App\Http\Middleware\RequestLoggingMiddleware::class]);
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '*') === '*' ? '*' : array_map('trim', explode(',', (string) env('TRUSTED_PROXIES'))));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
