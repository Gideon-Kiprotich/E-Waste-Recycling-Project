<?php

namespace Tests\Support;

use App\Actions\ManagePointStaff;
use App\Actions\PlanRoutes;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait ReceiptFixtures
{
    use RoutingFixtures;

    protected function pickupWork(int $count = 1, array $items = []): array
    {
        [$admin,$owner,$collector,$place,$depot,$vehicle] = $this->routingSetup();
        $assignments = [];
        for ($n = 0; $n < $count; $n++) {
            $assignments[] = $this->accepted($admin, $owner, $collector, $place, $n === 0 ? $items : []);
        }
        $run = app(PlanRoutes::class)->create($admin, $this->routeFields($depot, $vehicle, $assignments));
        app(PlanRoutes::class)->approve($admin, $run, $this->approvalFields());
        $route = DB::table('routes')->where('run_id', $run)->where('method', 'proposed')->value('id');
        $ordered = DB::table('route_stops')->where('route_id', $route)->orderBy('position')->pluck('assignment_id')->all();

        return [$admin, $owner, $collector, $route, $ordered];
    }

    protected function visitFields(string $request, array $outcomes = []): array
    {
        $r = DB::table('requests')->where('id', $request)->first();
        $a = DB::table('assignments')->where('request_id', $request)->where('active', true)->first();
        $route = $a ? DB::table('route_stops as s')->join('routes as ro', 'ro.id', '=', 's.route_id')->join('route_approvals as ap', 'ap.run_id', '=', 'ro.run_id')->where('s.assignment_id', $a->id)->where('ro.method', 'proposed')->where('ap.state', 'approved')->value('ro.id') : null;
        $rows = DB::table('request_items as ri')->join('subjects as s', 's.id', '=', 'ri.item_id')->where('ri.request_id', $request)->where('ri.active', true)->orderBy('ri.item_id')->get(['ri.id', 's.public_code']);

        return ['idempotency_key' => Str::uuid()->toString(), 'expected_version' => $r->version, 'assignment_id' => $a?->id, 'expected_assignment_version' => $a?->version, 'route_id' => $route, 'observed_at' => now('UTC')->format('Y-m-d\TH:i'), 'evidence_kind' => 'operator_reference', 'reference_issuer' => 'Synthetic operator log', 'reference_code' => 'SYN-2026-001', 'reference_attested' => '1', 'items' => $rows->map(fn ($row, $n) => ['request_item_id' => $row->id, 'code' => $row->public_code, 'outcome' => $outcomes[$n] ?? 'received', 'attested' => ($outcomes[$n] ?? 'received') === 'received' ? '1' : '0', 'observation' => 'Private synthetic physical comparison or failed-visit reason'])->all()];
    }

    protected function pointStaff(User $admin, string $place): User
    {
        $staff = User::factory()->create();
        $staff->membership()->update(['role' => Role::Processor]);
        $staff->refresh();
        app(ManagePointStaff::class)->save($admin, $staff->id, ['operation' => 'link', 'point_ref' => $place.':'.DB::table('places')->where('id', $place)->value('version'), 'expected_version' => 1, 'reason' => 'Synthetic point scope', 'idempotency_key' => Str::uuid()->toString()]);

        return $staff->refresh();
    }

    protected function receiptSignIn(User $user): static
    {
        $this->actingAs($user)->withSession(['auth_session_version' => $user->session_version]);

        return $this;
    }
}
