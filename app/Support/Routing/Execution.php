<?php

namespace App\Support\Routing;

use App\Actions\PlanRoutes;
use App\Models\User;
use App\Support\Assignments\Eligibility;
use App\Support\Assignments\Input;
use App\Support\Collection\AdminMutation;
use App\Support\Collection\Review;
use App\Support\Receipts\Context;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class Execution
{
    public function current(string $runId): bool
    {
        $run = DB::table('route_runs')->where('id', $runId)->firstOrFail();
        $snapshot = PlanRoutes::snapshot($run);
        $approval = DB::table('route_approvals')->where('run_id', $runId)->first();
        if ($approval?->state !== 'approved') {
            return false;
        }
        foreach ($snapshot['dependencies'] as $d) {
            $row = DB::table($d['kind'])->where('id', $d['record_id'])->first();
            if ($d['kind'] === 'memberships' && $row && ($row->place_id ?? null) === null) {
                unset($row->place_id);
            }
            if (hash('sha256', json_encode($row, JSON_THROW_ON_ERROR)) === $d['fingerprint']) {
                continue;
            }
            $change = DB::table('receipt_route_changes as ch')->join('route_consumptions as co', 'co.visit_id', '=', 'ch.visit_id')->join('routes as ro', 'ro.id', '=', 'co.route_id')->join('receipt_visits as v', 'v.id', '=', 'ch.visit_id')->where('ro.run_id', $runId)->where('ch.kind', $d['kind'])->where('ch.record_id', $d['record_id'])->orderByDesc('v.recorded_at')->first(['ch.after_row']);
            if (! $change || ! DB::selectOne('SELECT to_jsonb(t)=?::jsonb AS matches FROM '.$d['kind'].' t WHERE id=?', [$change->after_row, $d['record_id']])?->matches) {
                return false;
            }
        }
        // Dates can expire without a row update. Recheck every unconsumed job's live eligibility.
        foreach (DB::table('routes as ro')->join('route_stops as s', 's.route_id', '=', 'ro.id')->join('assignments as a', 'a.id', '=', 's.assignment_id')->where('ro.run_id', $runId)->where('ro.method', 'proposed')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('route_consumptions as co')->whereColumn('co.route_id', 'ro.id')->whereColumn('co.assignment_id', 'a.id'))->whereNotExists(fn ($q) => $q->selectRaw('1')->from('route_finishes as f')->whereColumn('f.route_id', 'ro.id'))->select('a.*')->get() as $a) {
            if (! app(Eligibility::class)->current($a)) {
                return false;
            }
        }

        return true;
    }

    public function change(string $visit, string $kind, string $id, array $changes): void
    {
        DB::insert('INSERT INTO receipt_route_changes (visit_id,kind,record_id,before_row,after_row) SELECT ?,?,?,to_jsonb(t),to_jsonb(t)||?::jsonb FROM '.$kind.' t WHERE id=?', [$visit, $kind, $id, json_encode($changes, JSON_THROW_ON_ERROR), $id]);
        DB::table($kind)->where('id', $id)->update($changes);
    }

    public function finish(User $actor, string $routeId, array $fields): string
    {
        $fields = Input::validate($fields, ['idempotency_key' => ['required', 'uuid'], 'unfinished' => ['present', 'array', 'max:50'], 'unfinished.*' => ['uuid', 'distinct'], 'reason' => ['required', 'string', 'max:1000'], 'confirm' => ['required', 'accepted']]);
        sort($fields['unfinished']);

        return DB::transaction(function () use ($actor, $routeId, $fields) {
            Review::lock();
            $actor = app(Context::class)->actor($actor);
            $route = DB::table('routes')->where('id', $routeId)->where('collector_id', $actor->id)->where('method', 'proposed')->firstOrFail();
            $action = 'finish_route:'.$routeId;
            $hash = AdminMutation::hash($fields);
            if ($retry = AdminMutation::retry($actor, $action, $fields['idempotency_key'], $hash)) {
                return $retry;
            }
            abort_unless($this->current($route->run_id) && ! DB::table('route_finishes')->where('route_id', $routeId)->exists(), 409, 'Route changed or already finished. Ask the Administrator to resolve outstanding work.');
            $unfinished = DB::table('route_stops as s')->where('s.route_id', $routeId)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('route_consumptions as co')->whereColumn('co.route_id', 's.route_id')->whereColumn('co.assignment_id', 's.assignment_id'))->orderBy('s.assignment_id')->pluck('s.assignment_id')->all();
            abort_unless($unfinished === $fields['unfinished'], 409, 'The unfinished stops changed. Reload before confirming finish.');
            $command = AdminMutation::command($actor, $action, $fields['idempotency_key'], $hash, $routeId);
            DB::table('route_finishes')->insert(['route_id' => $routeId, 'actor_id' => $actor->id, 'command_id' => $command, 'recorded_at' => now(), 'unfinished' => json_encode($unfinished), 'reason_ciphertext' => Crypt::encryptString($fields['reason'])]);
            $approval = DB::table('route_approvals')->where('run_id', $route->run_id)->first();
            $assignments = DB::table('route_stops')->where('route_id', $routeId)->pluck('assignment_id');
            DB::table('route_claims')->where('approval_id', $approval->id)->where(function ($q) use ($assignments, $route) {
                $q->where(fn ($q) => $q->where('kind', 'assignment')->whereIn('record_id', $assignments))->orWhere(fn ($q) => $q->where('kind', 'vehicle')->where('record_id', $route->vehicle_id))->orWhere(fn ($q) => $q->where('kind', 'collector')->where('record_id', $route->collector_id));
            })->delete();
            if (! DB::table('routes as r')->where('r.run_id', $route->run_id)->where('r.method', 'proposed')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('route_finishes as f')->whereColumn('f.route_id', 'r.id'))->exists()) {
                DB::table('route_approvals')->where('id', $approval->id)->update(['state' => 'finished', 'version' => $approval->version + 1]);
            }
            AdminMutation::event($actor, 'route_execution', $routeId, 1, 'tour_finished', $command, ['unfinished_assignment_ids' => $unfinished, 'custody_changed' => false]);

            return $routeId;
        });
    }
}
