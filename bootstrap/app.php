<?php

use App\Http\Middleware\EnsureCurrentAccount;
use App\Http\Middleware\RequireRole;
use App\Support\Devices\SafeInput;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectUsersTo('/dashboard');
        $middleware->alias([
            'account.current' => EnsureCurrentAccount::class,
            'role' => RequireRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['token', 'password', 'password_confirmation', 'current_password', 'identifier', 'photo']);
        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->routeIs('items.store', 'items.update') || $request->expectsJson()) {
                return null;
            }

            // Action-level duplicate/upload errors need the same safe flash policy as form validation.
            return redirect($exception->redirectTo ?? url()->previous())
                ->withErrors($exception->errors(), $exception->errorBag)
                ->withInput(SafeInput::from($request));
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
