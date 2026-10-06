<?php

use App\Http\Middleware\EnsureCurrentAccount;
use App\Http\Middleware\RequireRole;
use App\Support\Collection\DirectoryInput;
use App\Support\Collection\Input;
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
        $exceptions->dontFlash(['token', 'password', 'password_confirmation', 'current_password', 'identifier', 'photo', 'address', 'contact', 'latitude', 'longitude', 'reason']);
        $exceptions->render(function (ValidationException $exception, Request $request) {
            if ($request->routeIs('admin.*') && ! $request->expectsJson()) {
                $safe = $request->routeIs('admin.reviews.decide') ? array_intersect_key(DirectoryInput::safe($request->all()), array_flip(['idempotency_key', 'expected_version'])) : DirectoryInput::safe($request->all());
                if ($request->routeIs('admin.reviews.decide') && in_array($request->input('decision'), ['ready', 'hold'], true)) {
                    $safe['decision'] = $request->input('decision');
                }

                return redirect()->route($request->routeIs('admin.reviews.decide') ? 'admin.reviews.show' : ($request->route('record') ? 'admin.directory.edit' : 'admin.directory.create'), $request->routeIs('admin.reviews.decide') ? [$request->route('collection')] : array_filter([$request->route('kind'), $request->route('record')]))->withErrors($exception->errors())->withInput($safe);
            }
            if ($request->routeIs('requests.change') && ! $request->expectsJson()) {
                return redirect()->route('requests.show', $request->route('collection'))->withErrors($exception->errors())
                    ->withInput(Input::safeChange($request->all()));
            }
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
