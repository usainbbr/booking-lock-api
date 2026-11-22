<?php

use App\Exceptions\BookingConflictException;
use App\Exceptions\InactiveResourceException;
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
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(fn (BookingConflictException $e) => response()->json([
            'message' => $e->getMessage(),
        ], 409));

        $exceptions->render(fn (InactiveResourceException $e) => response()->json([
            'message' => $e->getMessage(),
        ], 422));
    })->create();
