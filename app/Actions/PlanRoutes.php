<?php

namespace App\Actions;

use App\Models\User;
use App\Support\Assignments\Input;
use App\Support\Collection\AdminMutation;
use App\Support\Routing\Planner;
use App\Support\Routing\Snapshot;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PlanRoutes
{
    public function create(User $actor, array $fields): string
    {
        $start = microtime(true);
        $fields = Input::validate($fields, ['operator_id' => ['required', 'uuid'], 'depot_id' => ['required', 'uuid'], 'vehicle_refs' => ['required', 'array', 'min:1', 'max:3'], 'vehicle_refs.*' => ['required', 'string', 'distinct', Input::referenceRule()], 'assignment_refs' => ['required', 'array', 'min:1', 'max:50'], 'assignment_refs.*' => ['required', 'string', 'distinct', Input::referenceRule()], 'mode' => ['required', Rule::in(['geometric'])], 'speed_kmh' => ['required', 'numeric', 'between:1,100'], 'service_seconds' => ['required', 'integer', 'between:0,86400'], 'idempotency_key' => ['required', 'uuid']]);
        sort($fields['vehicle_refs']);
        sort($fields['assignment_refs']);
        $hash = AdminMutation::hash($fields);
        $snapshot = DB::transaction(function () use ($actor, $fields, $hash): array|string {
            $actor = AdminMutation::actor($actor);
            if ($retry = AdminMutation::retry($actor, 'plan_routes', $fields['idempotency_key'], $hash)) {
                return $retry;
            }
            unset($fields['idempotency_key']);

            return app(Snapshot::class)->capture($fields);
        });
        if (is_string($snapshot)) {
            return $snapshot;
        }
        // The transaction gate and all row locks are released before matrix/calculation work.
        $planner = app(Planner::class);
        $snapshot['matrix'] = $planner->matrix($snapshot['jobs'], $snapshot['depot'], $snapshot['speed_kmh']);
        $results = $planner->compare($snapshot, $start + 10);

        return DB::transaction(function () use ($actor, $fields, $hash, $snapshot, $results, $start): string {
            $actor = AdminMutation::actor($actor);
            if ($retry = AdminMutation::retry($actor, 'plan_routes', $fields['idempotency_key'], $hash)) {
                return $retry;
            }
            $id = Str::uuid()->toString();
            $json = json_encode($snapshot, JSON_THROW_ON_ERROR);
            DB::table('route_runs')->insert(['id' => $id, 'created_by' => $actor->id, 'operator_id' => $snapshot['operator']['id'], 'depot_id' => $snapshot['depot']['id'], 'created_at' => now(), 'snapshot_ciphertext' => Crypt::encryptString($json), 'snapshot_hash' => AdminMutation::hash(['snapshot' => $json]), 'matrix_source' => $snapshot['source'], 'algorithm_version' => Planner::VERSION, 'results' => json_encode($results, JSON_THROW_ON_ERROR), 'local_seconds' => microtime(true) - $start]);
            foreach ($snapshot['dependencies'] as $dep) {
                DB::table('route_dependencies')->insert(['run_id' => $id, 'kind' => $dep['kind'], 'record_id' => $dep['record_id']]);
            }
            $jobs = array_column($snapshot['jobs'], null, 'id');
            foreach (['baseline', 'proposed'] as $method) {
                foreach ($results[$method]['routes'] as $route) {
                    $routeId = Str::uuid()->toString();
                    DB::table('routes')->insert(['id' => $routeId, 'run_id' => $id, 'vehicle_id' => $route['vehicle_id'], 'collector_id' => $route['collector_id'], 'method' => $method]);
                    foreach ($route['stops'] as $position => $assignment) {
                        DB::table('route_stops')->insert(['route_id' => $routeId, 'assignment_id' => $assignment, 'position' => $position + 1, 'request_version' => $jobs[$assignment]['request_version'], 'estimated_kg' => $jobs[$assignment]['kg']]);
                    }
                }
            }
            $command = AdminMutation::command($actor, 'plan_routes', $fields['idempotency_key'], $hash, $id);
            AdminMutation::event($actor, 'route_run', $id, 1, 'route_comparison_created', $command, ['source' => $snapshot['source'], 'submitted' => count($fields['assignment_refs']), 'excluded' => count($snapshot['excluded']), 'eligible' => count($snapshot['jobs'])]);

            return $id;
        });
    }

    public function approve(User $actor, string $runId, array $fields): string
    {
        $fields = Input::validate($fields, ['idempotency_key' => ['required', 'uuid'], 'supersedes' => ['nullable', 'uuid'], 'expected_superseded_version' => ['required_with:supersedes', 'nullable', 'integer', 'min:1']]);
        try {
            return DB::transaction(function () use ($actor, $runId, $fields): string {
                $actor = AdminMutation::actor($actor);
                $action = 'approve_routes:'.$runId;
                $hash = AdminMutation::hash($fields);
                if ($retry = AdminMutation::retry($actor, $action, $fields['idempotency_key'], $hash)) {
                    return $retry;
                }
                $run = DB::table('route_runs')->where('id', $runId)->lockForUpdate()->firstOrFail();
                $snapshot = self::snapshot($run);
                $dependencies = collect($snapshot['dependencies'])->sortBy(fn ($d) => $d['kind'].':'.$d['record_id']);
                foreach ($dependencies->groupBy('kind') as $table => $rows) {
                    DB::table($table)->whereIn('id', $rows->pluck('record_id'))->orderBy('id')->lockForUpdate()->get();
                }
                abort_unless(app(Snapshot::class)->current($snapshot), 409, 'Frozen inputs changed. Resolve setup/review/acknowledgment and create a new comparison.');
                $results = json_decode($run->results, true, flags: JSON_THROW_ON_ERROR);
                app(Planner::class)->validate($snapshot, $results['proposed']);
                abort_if(! $results['proposed']['served'], 422, 'No feasible stops to approve. Change the declared inputs and compare again.');
                abort_if(DB::table('route_approvals')->where('run_id', $runId)->exists(), 409, 'This comparison already has an approval history. Create a new comparison.');
                if (! empty($fields['supersedes'])) {
                    $old = DB::table('route_approvals')->where('id', $fields['supersedes'])->lockForUpdate()->firstOrFail();
                    abort_unless($old->state === 'approved' && $old->version === (int) $fields['expected_superseded_version'], 409, 'The approval to supersede changed. Reload.');
                    abort_unless(DB::table('route_runs')->where('id', $old->run_id)->value('operator_id') === $run->operator_id, 409, 'Supersession must remain within this collecting operator. Withdraw unrelated plans separately.');
                    DB::table('route_approvals')->where('id', $old->id)->update(['state' => 'superseded', 'version' => $old->version + 1]);
                }
                $id = Str::uuid()->toString();
                DB::table('route_approvals')->insert(['id' => $id, 'run_id' => $runId, 'approved_by' => $actor->id, 'approved_at' => now(), 'state' => 'approved', 'version' => 1]);
                $claims = [];
                foreach ($results['proposed']['served'] as $assignment) {
                    $claims[] = ['approval_id' => $id, 'kind' => 'assignment', 'record_id' => $assignment];
                }
                foreach ($results['proposed']['routes'] as $route) {
                    foreach (['vehicle' => $route['vehicle_id'], 'collector' => $route['collector_id']] as $kind => $ref) {
                        $claims[] = ['approval_id' => $id, 'kind' => $kind, 'record_id' => $ref];
                    }
                }
                usort($claims, fn ($a, $b) => strcmp($a['kind'].$a['record_id'], $b['kind'].$b['record_id']));
                DB::table('route_claims')->insert($claims);
                $command = AdminMutation::command($actor, $action, $fields['idempotency_key'], $hash, $id);
                AdminMutation::event($actor, 'route_approval', $id, 1, 'route_plan_approved', $command, ['run_id' => $runId]);

                return $id;
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23505' && (str_contains($e->errorInfo[2] ?? '', 'route_claims_pkey') || str_contains($e->errorInfo[2] ?? '', 'route_approvals_run_id_unique'))) {
                abort(409, 'An assignment, vehicle or Collector already belongs to an active approved plan. Withdraw that approval or explicitly supersede it before approving another.');
            }
            throw $e;
        }
    }

    public function withdraw(User $actor, string $id, array $fields): string
    {
        $fields = Input::validate($fields, ['idempotency_key' => ['required', 'uuid'], 'expected_version' => ['required', 'integer', 'min:1']]);

        return DB::transaction(function () use ($actor, $id, $fields): string {
            $actor = AdminMutation::actor($actor);
            $action = 'withdraw_routes:'.$id;
            $hash = AdminMutation::hash($fields);
            if ($retry = AdminMutation::retry($actor, $action, $fields['idempotency_key'], $hash)) {
                return $retry;
            }
            $a = DB::table('route_approvals')->where('id', $id)->lockForUpdate()->firstOrFail();
            abort_unless($a->state === 'approved' && $a->version === (int) $fields['expected_version'], 409, 'Approval changed. Reload Route planning.');
            DB::table('route_approvals')->where('id', $id)->update(['state' => 'withdrawn', 'version' => $a->version + 1]);
            $command = AdminMutation::command($actor, $action, $fields['idempotency_key'], $hash, $id);
            AdminMutation::event($actor, 'route_approval', $id, $a->version + 1, 'route_plan_withdrawn', $command, []);

            return $id;
        });
    }

    public static function snapshot(object $run): array
    {
        return json_decode(Crypt::decryptString($run->snapshot_ciphertext), true, flags: JSON_THROW_ON_ERROR);
    }
}
