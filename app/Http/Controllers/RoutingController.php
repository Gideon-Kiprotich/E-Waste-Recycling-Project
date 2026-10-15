<?php

namespace App\Http\Controllers;

use App\Actions\ManageVehicle;
use App\Actions\PlanRoutes;
use App\Support\Routing\Execution;
use App\Support\Routing\Snapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoutingController extends Controller
{
    public function index(Request $request)
    {
        $operators = DB::table('organizations')->where('review_status', 'approved')->whereIn('kind', ['collector', 'both'])->orderBy('name')->get();
        $operator = $operators->firstWhere('id', $request->query('operator_id', old('operator_id')));
        $depots = DB::table('places')->where('organization_id', $operator?->id)->where('kind', 'depot')->where('active', true)->where('review_status', 'approved')->orderBy('name')->get();
        $vehicles = DB::table('vehicles as v')->join('users as u', 'u.id', '=', 'v.collector_id')->where('v.organization_id', $operator?->id)->orderBy('v.code')->get(['v.*', 'u.display_name']);
        $assignments = DB::table('assignments as a')->join('requests as r', 'r.id', '=', 'a.request_id')->join('users as u', 'u.id', '=', 'a.collector_id')->where('a.operator_id', $operator?->id)->where('a.active', true)->orderBy('r.created_at')->limit(200)->get(['a.id', 'a.version', 'a.state', 'r.id as request_id', 'r.public_code', 'u.display_name']);
        $runs = DB::table('route_runs as r')->leftJoin('route_approvals as a', 'a.run_id', '=', 'r.id')->orderByDesc('r.created_at')->paginate(12, ['r.id', 'r.created_at', 'a.state']);

        return $this->view('routing.index', compact('operators', 'operator', 'depots', 'vehicles', 'assignments', 'runs'));
    }

    public function vehicleForm(?string $vehicle = null)
    {
        $entity = $vehicle ? DB::table('vehicles')->where('id', $vehicle)->firstOrFail() : null;
        $operators = DB::table('organizations')->where('review_status', 'approved')->whereIn('kind', ['collector', 'both'])->orderBy('name')->pluck('name', 'id')->all();
        $collectors = DB::table('memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')->join('organizations as o', 'o.id', '=', 'm.organization_id')->where('m.role', 'collector')->where('m.active', true)->where('u.status', 'active')->where('o.review_status', 'approved')->whereIn('o.kind', ['collector', 'both'])->orderBy('u.display_name')->get(['u.id', 'u.display_name', 'o.name']);
        $collectorOptions = $collectors->mapWithKeys(fn ($c) => [$c->id => $c->display_name.' · '.$c->name])->all();

        return $this->view('routing.vehicle', compact('entity', 'operators', 'collectorOptions'));
    }

    public function saveVehicle(Request $request, ManageVehicle $action, ?string $vehicle = null)
    {
        $id = $action->save($request->user(), $vehicle, $request->except('_token'));

        return redirect()->route('admin.routing.vehicles.edit', $id)->with('status', 'Vehicle declaration saved. Affected route approvals are stale; request reviews and Collector acknowledgments are unchanged.');
    }

    public function create(Request $request, PlanRoutes $action)
    {
        $id = $action->create($request->user(), $request->except('_token'));

        return redirect()->route('admin.routing.show', $id)->with('status', 'Frozen comparison retained. Approval is separate; no appointment, receipt or custody change was recorded.');
    }

    public function show(string $run)
    {
        $record = DB::table('route_runs')->where('id', $run)->firstOrFail();
        $snapshot = PlanRoutes::snapshot($record);
        $results = json_decode($record->results, true, flags: JSON_THROW_ON_ERROR);
        $current = app(Snapshot::class)->current($snapshot);
        $approval = DB::table('route_approvals')->where('run_id', $run)->first();
        $active = DB::table('route_approvals as a')->join('route_runs as r', 'r.id', '=', 'a.run_id')->where('r.operator_id', $record->operator_id)->where('a.state', 'approved')->orderBy('a.approved_at')->get(['a.*']);
        $history = $approval ? DB::table('route_approval_history')->where('approval_id', $approval->id)->orderBy('version')->get() : collect();

        return $this->view('routing.show', compact('record', 'snapshot', 'results', 'current', 'approval', 'active', 'history'));
    }

    public function approve(Request $request, PlanRoutes $action, string $run)
    {
        $action->approve($request->user(), $run, $request->except('_token'));

        return redirect()->route('admin.routing.show', $run)->with('status', 'Proposed plan approved. Named Collectors can view ordered stops. This is a simulated pickup plan, not a booked appointment or physical receipt.');
    }

    public function withdraw(Request $request, PlanRoutes $action, string $approval)
    {
        $action->withdraw($request->user(), $approval, $request->except('_token'));

        return redirect()->route('admin.routing.index')->with('status', 'Approval withdrawn and route claims released. Assignments, item reservations and custody are unchanged.');
    }

    public function work(Request $request)
    {
        $routes = DB::table('routes as r')->join('route_approvals as a', 'a.run_id', '=', 'r.run_id')->where('r.collector_id', $request->user()->id)->where('r.method', 'proposed')->where('a.state', 'approved')->orderByDesc('a.approved_at')->get(['r.id', 'r.run_id']);
        $routes = $routes->filter(function ($r) {
            $run = DB::table('route_runs')->where('id', $r->run_id)->first();

            return ! DB::table('route_finishes')->where('route_id', $r->id)->exists() && app(Execution::class)->current($run->id);
        });

        return $this->view('routing.work', compact('routes'));
    }

    public function stops(Request $request, string $route)
    {
        $record = DB::table('routes')->where('id', $route)->where('collector_id', $request->user()->id)->where('method', 'proposed')->firstOrFail();
        $approval = DB::table('route_approvals')->where('run_id', $record->run_id)->first();
        abort_unless($approval?->state === 'approved', 409, 'This route approval is no longer active. Return to Approved routes.');
        $run = DB::table('route_runs')->where('id', $record->run_id)->firstOrFail();
        $snapshot = PlanRoutes::snapshot($run);
        abort_unless(! DB::table('route_finishes')->where('route_id', $route)->exists() && app(Execution::class)->current($run->id), 409, 'Route inputs or eligibility changed. Ask the Administrator for a fresh comparison and approval.');
        $tour = collect(json_decode($run->results, true, flags: JSON_THROW_ON_ERROR)['proposed']['routes'])->firstWhere('vehicle_id', $record->vehicle_id);
        $jobs = array_column($snapshot['jobs'], null, 'id');

        $consumed = DB::table('route_consumptions')->where('route_id', $route)->pluck('visit_id', 'assignment_id')->all();

        return $this->view('routing.stops', compact('record', 'snapshot', 'tour', 'jobs', 'consumed'));
    }

    private function view(string $view, array $data)
    {
        return response()->view($view, $data)->header('Cache-Control', 'private, no-store');
    }
}
