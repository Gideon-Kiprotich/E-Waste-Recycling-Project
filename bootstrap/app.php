<?php

use App\Http\Middleware\EnsureCurrentAccount;
use App\Http\Middleware\RequireRole;
use App\Support\Collection\DirectoryInput;
use App\Support\Collection\Input;
use App\Support\Collection\SiteLocation;
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
            if ($request->routeIs('admin.routing.*') && ! $request->expectsJson()) {
                $route = match ($request->route()->getName()) {
                    'admin.routing.vehicles.store' => 'admin.routing.vehicles.create',
                    'admin.routing.vehicles.update' => 'admin.routing.vehicles.edit',
                    'admin.routing.approve' => 'admin.routing.show',
                    default => 'admin.routing.index',
                };
                $safe = array_filter(array_intersect_key($request->all(), array_flip(['operator_id', 'depot_id', 'collector_id', 'organization_id', 'code', 'synthetic', 'capacity_kg', 'max_duration_seconds', 'available', 'speed_kmh', 'service_seconds', 'mode', 'idempotency_key', 'expected_version'])), fn ($v) => is_string($v) && strlen($v) <= 100);
                foreach (['vehicle_refs' => 3, 'assignment_refs' => 50] as $field => $limit) {
                    $values = $request->input($field);
                    if (is_array($values)) {
                        $safe[$field] = array_values(array_slice(array_filter($values, fn ($v) => is_string($v) && preg_match(substr(App\Support\Assignments\Input::referenceRule(), 6), $v)), 0, $limit));
                    }
                }

                return redirect()->route($route, array_filter([$request->route('vehicle') ?? $request->route('run')]))->withErrors($exception->errors())->withInput($safe);
            }
            if ($request->routeIs('admin.assignments.save', 'admin.collectors.store', 'admin.collectors.update', 'collector.assignments.respond') && ! $request->expectsJson()) {
                $route = match ($request->route()->getName()) {
                    'admin.assignments.save' => 'admin.assignments.show',
                    'admin.collectors.store' => 'admin.collectors.create',
                    'admin.collectors.update' => 'admin.collectors.edit',
                    default => 'collector.assignments.show',
                };
                $parameters = array_filter([$request->route('collection') ?? $request->route('collector') ?? $request->route('assignment')]);

                return redirect()->route($route, $parameters)->withErrors($exception->errors())->withInput(App\Support\Assignments\Input::safe($request->all()));
            }
            if ($request->routeIs('admin.directory.*', 'admin.reviews.*') && ! $request->expectsJson()) {
                if ($request->route('kind') === 'place') {
                    SiteLocation::remember($request->route('record'), $request->all());
                }
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
