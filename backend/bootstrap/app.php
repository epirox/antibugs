<?php

use App\Exceptions\InvalidStatusTransitionException;
use App\Http\Middleware\AssignTraceId;
use App\Http\Middleware\EnsureUserHasRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [AssignTraceId::class]);

        $middleware->alias([
            'role' => EnsureUserHasRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Transición inválida de la State Machine → 422 Unprocessable Entity
        $exceptions->render(function (InvalidStatusTransitionException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['status' => [$e->getMessage()]],
                'from' => $e->from->value,
                'to' => $e->to->value,
            ], 422);
        });

        $exceptions->render(function (JWTException $e, Request $request) {
            return response()->json(['message' => 'Token inválido o expirado.'], 401);
        });
    })->create();
