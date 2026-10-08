<?php

use App\Http\DeviceApi\DeviceApiException;
use App\Http\DeviceApi\DeviceApiExceptionRenderer;
use App\Http\Middleware\DeviceApiContext;
use App\Http\Middleware\EnsureDeviceAbility;
use App\Http\Middleware\LimitDevicePayload;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'device.context' => DeviceApiContext::class,
            'device.ability' => EnsureDeviceAbility::class,
            'device.payload' => LimitDevicePayload::class,
        ]);

        // Request context (and forgetting any previously resolved device
        // credential) must run before the device guard authenticates.
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: DeviceApiContext::class,
        );

        // Laravel Cloud terminates TLS at its edge.
        $middleware->trustProxies(at: '*');

        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : route('filament.app.auth.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(fn (Throwable $exception, Request $request) => DeviceApiExceptionRenderer::render($exception, $request));

        $exceptions->dontReport([
            DeviceApiException::class,
        ]);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
