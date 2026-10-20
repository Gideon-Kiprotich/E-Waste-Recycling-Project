<?php

use App\Http\Middleware\EnsureCurrentAccount;
use App\Http\Middleware\RequireRole;
use App\Support\Assessments\Context;
use App\Support\Assessments\Schemas;
use App\Support\Collection\DirectoryInput;
use App\Support\Collection\Input;
use App\Support\Collection\SiteLocation;
use App\Support\Devices\SafeInput;
use App\Support\Sanitization\Policy;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
            if ($request->routeIs('restrictions.open', 'restrictions.store') && ! $request->expectsJson()) {
                $key = $request->input('idempotency_key');
                $safe = is_string($key) && Str::isUuid($key) ? ['idempotency_key' => $key] : [];
                if ($request->routeIs('restrictions.open')) {
                    return redirect()->route('restrictions.source', [$request->route('kind'), $request->route('source'), $request->route('fact')])->withErrors($exception->errors())->withInput($safe);
                }

                return redirect()->route('restrictions.show', ['case' => $request->route('case'), 'action' => $request->route('kind')])->withErrors($exception->errors())->withInput($safe);
            }
            if ($request->routeIs('downstream.*', 'admin.output-profiles.*') && ! $request->expectsJson()) {
                $key = $request->input('idempotency_key');
                $safe = is_string($key) && Str::isUuid($key) ? ['idempotency_key' => $key] : [];
                if ($request->routeIs('admin.output-profiles.*')) {
                    return redirect()->route('admin.output-profiles.index')->withErrors($exception->errors())->withInput($safe);
                }
                $shipment = $request->input('shipment_id');
                if (is_string($shipment) && Str::isUuid($shipment)) {
                    return redirect()->route('downstream.shipment', ['shipment' => $shipment, 'action' => $request->route('kind')])->withErrors($exception->errors())->withInput($safe);
                }
                if ($request->routeIs('downstream.authorize')) {
                    return redirect()->route('downstream.index')->withErrors($exception->errors())->withInput($safe);
                }

                return redirect()->route('downstream.show', ['batch' => $request->route('batch'), 'action' => $request->route('kind')])->withErrors($exception->errors())->withInput($safe);
            }
            if ($request->routeIs('batches.store', 'batches.authorize') && ! $request->expectsJson()) {
                $key = $request->input('idempotency_key');
                $safe = is_string($key) && Str::isUuid($key) ? ['idempotency_key' => $key] : [];
                $batch = $request->route('batch');
                if (! DB::table('dismantling_batches')->where('subject_id', $batch)->exists()) {
                    return redirect()->route('batches.create')->withErrors($exception->errors())->withInput($safe);
                }

                return redirect()->route('batches.show', ['batch' => $batch, 'action' => $request->routeIs('batches.authorize') ? 'authorize' : $request->route('kind'), 'target' => is_string($request->input('target_id')) && Str::isUuid($request->input('target_id')) ? $request->input('target_id') : null, 'medium' => $request->route('medium')])->withErrors($exception->errors())->withInput($safe);
            }
            if ($request->routeIs('refurbishment.store') && ! $request->expectsJson()) {
                $key = $request->input('idempotency_key');
                $safe = is_string($key) && Str::isUuid($key) ? ['idempotency_key' => $key] : [];

                return redirect()->route('refurbishment.show', ['subject' => $request->route('subject'), 'action' => $request->route('kind')])->withErrors($exception->errors())->withInput($safe);
            }
            if ($request->routeIs('media-inventory.store') && ! $request->expectsJson()) {
                $safe = [];
                $key = $request->input('idempotency_key');
                if (is_string($key) && Str::isUuid($key)) {
                    $safe['idempotency_key'] = $key;
                }

                return redirect()->route('media-inventory.show', ['subject' => $request->route('subject'), 'action' => $request->route('kind')])->withErrors($exception->errors())->withInput($safe);
            }
            if ($request->routeIs('sanitization.store') && ! $request->expectsJson()) {
                $safe = [];
                $key = $request->input('idempotency_key');
                if (is_string($key) && Str::isUuid($key)) {
                    $safe['idempotency_key'] = $key;
                }
                $kind = $request->route('kind');
                foreach (['outcome' => ['success', 'failed', 'incomplete', 'unverifiable', 'satisfactory', 'unsatisfactory', 'accepted', 'rejected'], 'technique' => array_keys(Policy::definition()['techniques'])] as $name => $values) {
                    if (is_string($request->input($name)) && in_array($request->input($name), $values, true)) {
                        $safe[$name] = $request->input($name);
                    }
                }
                foreach (Policy::checks($kind) as $code => $label) {
                    if (in_array($request->input('checks.'.$code), ['YES', 'NO', 'UNKNOWN'], true)) {
                        $safe['checks'][$code] = $request->input('checks.'.$code);
                    }
                }

                return redirect()->route('sanitization.show', ['subject' => $request->route('subject'), 'action' => $kind])->withErrors($exception->errors())->withInput($safe);
            }
            if ($request->routeIs('assessments.store', 'assessments.decide') && ! $request->expectsJson()) {
                $safe = [];
                $key = $request->input('idempotency_key');
                if (is_string($key) && Str::isUuid($key)) {
                    $safe['idempotency_key'] = $key;
                }
                $row = app(Context::class)->authorized($request->user(), $request->route('subject'));
                $schema = Schemas::definition($row->category, $row->subtype);
                $answers = $request->input('answers');
                if (is_array($answers)) {
                    foreach ($schema['questions'] as $code => $question) {
                        $value = $answers[$code] ?? null;
                        if (is_string($value) && in_array($value, $question['values'], true)) {
                            $safe['answers'][$code] = $value;
                        }
                    }
                }
                if (in_array($request->input('pathway'), ['refurbish', 'recycle', 'hold'], true)) {
                    $safe['pathway'] = $request->input('pathway');
                }

                return redirect()->route('assessments.show', ['subject' => $request->route('subject'), 'action' => $request->routeIs('assessments.decide') ? 'decide' : 'inspect'])->withErrors($exception->errors())->withInput($safe);
            }
            if ($request->routeIs('handovers.store', 'handovers.change', 'handovers.lookup') && ! $request->expectsJson()) {
                $safe = [];
                $key = $request->input('idempotency_key');
                if (is_string($key) && Str::isUuid($key)) {
                    $safe['idempotency_key'] = $key;
                }
                $target = $request->route('handover');
                if ($target) {
                    return redirect()->route('handovers.show', ['handover' => $target, 'action' => $request->route('kind')])->withErrors($exception->errors())->withInput($safe);
                }
                $ref = $request->input('recipient_ref');
                if (is_string($ref) && preg_match(substr(App\Support\Assignments\Input::referenceRule(), 6), $ref)) {
                    return redirect()->route('handovers.create', explode(':', $ref)[0])->withErrors($exception->errors())->withInput($safe);
                }

                return redirect()->route('handovers.index')->withErrors($exception->errors());
            }
            if ($request->routeIs('receipts.store', 'receipts.lookup', 'admin.point-staff.save', 'collector.routes.finish') && ! $request->expectsJson()) {
                $name = $request->route()->getName();
                $route = match ($name) {
                    'admin.point-staff.save' => 'admin.point-staff', 'collector.routes.finish' => 'collector.routes.show', default => 'receipts.form'
                };
                $parameters = $name === 'admin.point-staff.save' ? [] : [$request->route('collection') ?? $request->route('route')];
                $safe = array_filter(array_intersect_key($request->all(), array_flip(['idempotency_key'])), fn ($v) => is_string($v) && Str::isUuid($v));

                return redirect()->route($route, $parameters)->withErrors($exception->errors())->withInput($safe);
            }
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
