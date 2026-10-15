<?php

namespace Tests\Feature;

use App\Actions\AllocateCollector;
use App\Actions\CollectionRequests;
use App\Actions\ManageCollector;
use App\Actions\ManageVehicle;
use App\Actions\PlanRoutes;
use App\Models\User;
use App\Support\Routing\Planner;
use App\Support\Routing\Snapshot;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\RoutingFixtures;
use Tests\TestCase;

class RoutePlanningTest extends TestCase
{
    use DatabaseTransactions,RoutingFixtures;

    private User $admin;

    private User $owner;

    private User $collector;

    private string $place;

    private string $depot;

    private string $vehicle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1]);
        [$this->admin,$this->owner,$this->collector,$this->place,$this->depot,$this->vehicle] = $this->routingSetup();
    }

    private function signIn(User $user): void
    {
        $this->actingAs($user)->withSession(['auth_session_version' => $user->session_version]);
        session()->save();
        $this->withCookie(config('session.cookie'), session()->getId());
    }

    private function comparison(): array
    {
        $a = $this->accepted($this->admin, $this->owner, $this->collector, $this->place);
        $f = $this->routeFields($this->depot, $this->vehicle, [$a]);
        $id = app(PlanRoutes::class)->create($this->admin, $f);

        return [$id, $a, $f];
    }

    private function rejected(callable $action, int $status = 409): void
    {
        try {
            $action();
            $this->fail('Unsafe operation succeeded.');
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }

    private function databaseReject(callable $action): void
    {
        try {
            DB::transaction(function () use ($action) {
                $action();
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
            $this->fail('Constraint failed to reject.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_http_journey_vehicle_compare_approve_collector_stops_and_private_boundaries(): void
    {
        [$run,$assignment] = $this->comparison();
        $this->signIn($this->admin);
        $this->get(route('admin.routing.index', ['operator_id' => DB::table('vehicles')->where('id', $this->vehicle)->value('organization_id')]))->assertOk()->assertSee('Freeze and compare routes')->assertSee('SYNTHETIC-100')->assertSee('Finish setup');
        $this->get(route('admin.routing.show', $run))->assertOk()->assertSee('Approve proposed plan')->assertSee('Haversine geometric simulation')->assertDontSee('Synthetic contact only');
        $this->post(route('admin.routing.approve', $run), $this->approvalFields())->assertRedirect(route('admin.routing.show', $run));
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $route = DB::table('routes')->where('run_id', $run)->where('method', 'proposed')->value('id');
        $this->signIn($this->collector);
        $this->get(route('collector.routes.index'))->assertOk()->assertSee('Open ordered stops');
        $this->get(route('collector.routes.show', $route))->assertOk()->assertSee('Open authorized pickup details')->assertDontSee('Synthetic contact only');
        $this->get(route('collector.assignments.show', $assignment))->assertOk()->assertSee('Synthetic contact only');
        $this->signIn($this->owner);
        $this->get(route('admin.routing.show', $run))->assertForbidden();
        $this->get(route('collector.routes.show', $route))->assertForbidden();
        $other = User::factory()->create();
        $other->membership()->update(['role' => 'collector']);
        $this->signIn($other->refresh());
        $this->get(route('collector.routes.show', $route))->assertNotFound();
    }

    public function test_storage_facet_not_double_counted_and_successful_historical_retries_stay_identical(): void
    {
        $drive = $this->device($this->owner, 'standalone_part', 'hdd');
        $a = $this->accepted($this->admin, $this->owner, $this->collector, $this->place, [$drive->subject_id]);
        $fields = $this->routeFields($this->depot, $this->vehicle, [$a]);
        $service = app(PlanRoutes::class);
        $run = $service->create($this->admin, $fields);
        $r = DB::table('route_runs')->where('id', $run)->first();
        $this->assertSame(0.25, PlanRoutes::snapshot($r)['jobs'][0]['kg']);
        $this->assertDatabaseHas('storage_assets', ['subject_id' => $drive->subject_id]);
        $before = DB::table('commands')->count();
        $this->assertSame($run, $service->create($this->admin, $fields));
        $this->assertSame($before, DB::table('commands')->count());
        $approve = $this->approvalFields();
        $id = $service->approve($this->admin, $run, $approve);
        $this->assertSame($id, $service->approve($this->admin, $run, $approve));
        $w = $this->approvalFields() + ['expected_version' => 1];
        $service->withdraw($this->admin, $id, $w);
        $this->assertSame($id, $service->withdraw($this->admin, $id, $w));
        $this->assertSame($id, $service->approve($this->admin, $run, $approve));
        $fields['speed_kmh'] = '30';
        $this->rejected(fn () => $service->create($this->admin, $fields));
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_missing_acceptance_and_stale_review_are_concrete_exclusions_not_safety_overrides(): void
    {
        $r = $this->readyRequest($this->admin, $this->owner);
        $a = app(AllocateCollector::class)->allocate($this->admin, $r, $this->offerFields($r, $this->collector, $this->place));
        $fields = $this->routeFields($this->depot, $this->vehicle, [$a]);
        $run = app(PlanRoutes::class)->create($this->admin, $fields);
        $s = PlanRoutes::snapshot(DB::table('route_runs')->where('id', $run)->first());
        $this->assertCount(0, $s['jobs']);
        $this->assertStringContainsString('acceptance required', $s['excluded'][0]['reason']);
        $this->rejected(fn () => app(PlanRoutes::class)->approve($this->admin, $run, $this->approvalFields()), 422);
        app(AllocateCollector::class)->respond($this->collector, $a, $this->responseFields($a));
        $fields = $this->routeFields($this->depot, $this->vehicle, [$a]);
        DB::table('requests')->where('id', $r)->update(['review_state' => 'stale', 'version' => DB::raw('version+1')]);
        $run = app(PlanRoutes::class)->create($this->admin, $fields);
        $s = PlanRoutes::snapshot(DB::table('route_runs')->where('id', $run)->first());
        $this->assertStringContainsString('review', $s['excluded'][0]['reason']);
    }

    public function test_claim_conflict_rolls_back_then_explicit_supersession_releases_only_route_claims(): void
    {
        [$first,$a,$fields] = $this->comparison();
        $second = app(PlanRoutes::class)->create($this->admin, array_replace($fields, $this->approvalFields()));
        $beforeItems = DB::table('request_items')->get()->toJson();
        $beforeCustody = DB::table('custody_current')->get()->toJson();
        $old = app(PlanRoutes::class)->approve($this->admin, $first, $this->approvalFields());
        $count = DB::table('commands')->count();
        $this->rejected(fn () => app(PlanRoutes::class)->approve($this->admin, $second, $this->approvalFields()));
        $this->assertDatabaseCount('route_approvals', 1);
        $this->assertSame($count, DB::table('commands')->count());
        $new = app(PlanRoutes::class)->approve($this->admin, $second, $this->approvalFields() + ['supersedes' => $old, 'expected_superseded_version' => 1]);
        $this->assertDatabaseHas('route_approvals', ['id' => $old, 'state' => 'superseded']);
        $this->assertSame(3, DB::table('route_claims')->where('approval_id', $new)->count());
        $this->assertSame($beforeItems, DB::table('request_items')->get()->toJson());
        $this->assertSame($beforeCustody, DB::table('custody_current')->get()->toJson());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_vehicle_edit_invalidates_approval_without_invalidating_acceptance_or_request_review(): void
    {
        [$run,$a] = $this->comparison();
        $id = app(PlanRoutes::class)->approve($this->admin, $run, $this->approvalFields());
        $request = DB::table('requests')->get()->toJson();
        app(ManageVehicle::class)->save($this->admin, $this->vehicle, ['collector_id' => $this->collector->id, 'capacity_kg' => '200', 'max_duration_seconds' => 21600, 'available' => '1', 'expected_version' => 1, 'idempotency_key' => Str::uuid()->toString()]);
        $this->assertDatabaseHas('route_approvals', ['id' => $id, 'state' => 'stale']);
        $this->assertDatabaseCount('route_claims', 0);
        $this->assertDatabaseHas('assignments', ['id' => $a, 'state' => 'accepted']);
        $this->assertSame($request, DB::table('requests')->get()->toJson());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_withdrawal_partial_cancellation_and_scope_changes_invalidate_approvals_and_private_reads(): void
    {
        foreach (['assignment', 'cancel', 'scope'] as $change) {
            if ($change === 'cancel') {
                $items = [$this->device($this->owner)->subject_id, $this->device($this->owner)->subject_id];
                $a = $this->accepted($this->admin, $this->owner, $this->collector, $this->place, $items);
                $run = app(PlanRoutes::class)->create($this->admin, $this->routeFields($this->depot, $this->vehicle, [$a]));
            } else {
                [$run,$a] = $this->comparison();
            }$id = app(PlanRoutes::class)->approve($this->admin, $run, $this->approvalFields());
            $assignment = DB::table('assignments')->where('id', $a)->first();
            $custody = DB::table('custody_current')->get()->toJson();
            if ($change === 'assignment') {
                app(AllocateCollector::class)->allocate($this->admin, $assignment->request_id, ['operation' => 'withdraw', 'assignment_id' => $a, 'expected_version' => 2, 'reason' => 'Synthetic withdrawal', 'idempotency_key' => Str::uuid()->toString()]);
            } elseif ($change === 'cancel') {
                $r = DB::table('requests')->where('id', $assignment->request_id)->first();
                $items = DB::table('request_items')->where('request_id', $r->id)->limit(1)->pluck('item_id')->all();
                app(CollectionRequests::class)->change($this->owner, $r->id, ['operation' => 'cancel', 'items' => $items, 'reason' => 'Synthetic cancellation', 'expected_version' => $r->version, 'idempotency_key' => Str::uuid()->toString()]);
            } else {
                app(ManageCollector::class)->save($this->admin, $this->collector->id, ['operation' => 'deactivate', 'expected_version' => $this->collector->membership->version, 'reason' => 'Synthetic revoke', 'idempotency_key' => Str::uuid()->toString()]);
            }
            $this->assertDatabaseHas('route_approvals', ['id' => $id, 'state' => 'stale']);
            $this->assertDatabaseCount('route_claims', 0);
            $this->assertSame($custody, DB::table('custody_current')->get()->toJson());
            if ($change === 'cancel') {
                $this->assertSame(1, DB::table('request_items')->where('request_id', $assignment->request_id)->where('active', true)->count());
            }DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
        }
    }

    public function test_out_of_band_location_drift_blocks_reads_and_approval_without_get_writes(): void
    {
        [$run,$a] = $this->comparison();
        $s = PlanRoutes::snapshot(DB::table('route_runs')->where('id', $run)->first());
        $r = DB::table('requests')->where('id', $s['jobs'][0]['request_id'])->first();
        DB::table('request_private_details')->where('id', $r->private_details_id)->update(['latitude' => '-1.290000']);
        $this->assertFalse(app(Snapshot::class)->current($s));
        $before = DB::table('domain_events')->count();
        $this->signIn($this->admin);
        $this->get(route('admin.routing.show', $run))->assertOk()->assertSee('Stale inputs')->assertDontSee('Approve proposed plan');
        $this->assertSame($before, DB::table('domain_events')->count());
        $this->rejected(fn () => app(PlanRoutes::class)->approve($this->admin, $run, $this->approvalFields()));
    }

    public function test_direct_constraint_violations_and_incomplete_claims_fail_at_deferred_enforcement(): void
    {
        [$run] = $this->comparison();
        $record = DB::table('route_runs')->where('id', $run)->first();
        $this->databaseReject(fn () => DB::table('route_runs')->where('id', $run)->update(['matrix_source' => 'road']));
        $this->databaseReject(fn () => DB::table('route_approvals')->insert(['id' => Str::uuid()->toString(), 'run_id' => $run, 'approved_by' => $this->admin->id, 'approved_at' => now(), 'state' => 'approved', 'version' => 1]));
        $id = app(PlanRoutes::class)->approve($this->admin, $run, $this->approvalFields());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $this->databaseReject(fn () => DB::table('route_claims')->where('approval_id', $id)->delete());
        $this->databaseReject(fn () => DB::table('route_claims')->insert(['approval_id' => $id, 'kind' => 'assignment', 'record_id' => Str::uuid()->toString()]));
        $this->assertDatabaseCount('route_claims', 3);
        $this->assertStringNotContainsString('-1.280000', $record->snapshot_ciphertext);
        $this->assertStringNotContainsString('Synthetic contact only', $record->snapshot_ciphertext);
    }

    public function test_forged_inputs_and_missing_vehicle_mapping_have_safe_associated_feedback(): void
    {
        [$run,$a,$fields] = $this->comparison();
        $this->signIn($this->admin);
        $this->post(route('admin.routing.create'), $fields + ['contact' => 'forged private data', 'password' => 'never flash'])->assertSessionHasErrors('submission')->assertSessionMissing('_old_input.contact')->assertSessionMissing('_old_input.password');
        $this->post(route('admin.routing.vehicles.store'), ['capacity_kg' => '0'])->assertSessionHasErrors('capacity_kg');
        $this->get(route('admin.routing.vehicles.create'))->assertOk()->assertSee('aria-invalid="true"', false)->assertSee('capacity_kg-error');
        $f = $fields;
        $f['vehicle_refs'] = [];
        try {
            app(PlanRoutes::class)->create($this->admin, $f);
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('vehicle_refs', $e->errors());
        }
    }

    public function test_calculation_has_no_open_transaction_and_forced_failure_leaves_no_run_or_command(): void
    {
        $a = $this->accepted($this->admin, $this->owner, $this->collector, $this->place);
        $f = $this->routeFields($this->depot, $this->vehicle, [$a]);
        $outer = DB::transactionLevel();
        $before = DB::table('commands')->count();
        $fake = \Mockery::mock(Planner::class)->makePartial();
        $fake->shouldReceive('compare')->once()->andReturnUsing(function () use ($outer) {
            $this->assertSame($outer, DB::transactionLevel());
            throw new \RuntimeException('Synthetic calculation failure');
        });
        $this->app->instance(Planner::class, $fake);
        try {
            app(PlanRoutes::class)->create($this->admin, $f);
            $this->fail();
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic calculation failure', $e->getMessage());
        }
        $this->assertDatabaseCount('route_runs', 0);
        $this->assertSame($before, DB::table('commands')->count());
    }

    public function test_supported_vehicle_and_comparison_forms_work_and_preserve_safe_choices_after_errors(): void
    {
        $a = $this->accepted($this->admin, $this->owner, $this->collector, $this->place);
        $fields = $this->routeFields($this->depot, $this->vehicle, [$a]);
        $this->signIn($this->admin);
        $response = $this->post(route('admin.routing.vehicles.store'), ['organization_id' => $fields['operator_id'], 'collector_id' => $this->collector->id, 'code' => 'SYNTHETIC-FORM', 'synthetic' => '1', 'capacity_kg' => '100', 'max_duration_seconds' => '21600', 'available' => '1', 'idempotency_key' => Str::uuid()->toString()]);
        $response->assertRedirect();
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('SYNTHETIC-FORM');
        $bad = array_replace($fields, ['speed_kmh' => '0']);
        $this->post(route('admin.routing.create'), $bad)->assertRedirect(route('admin.routing.index'))->assertSessionHasErrors('speed_kmh')->assertSessionHas('_old_input.assignment_refs');
        $this->get(route('admin.routing.index'))->assertOk()->assertSee('speed_kmh-error')->assertSee('aria-invalid="true"', false)->assertSee('routing-jobs');
        $response = $this->post(route('admin.routing.create'), $fields);
        $response->assertRedirect();
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('Approve proposed plan');
        $this->signIn($this->owner);
        $this->post(route('admin.routing.create'), $fields)->assertForbidden();
    }

    public function test_vehicle_mapping_bounds_and_unconfigured_depot_cannot_bypass_setup(): void
    {
        [$run,$a,$fields] = $this->comparison();
        foreach (['mode', 'depot_id', 'vehicle_refs'] as $field) {
            $bad = $fields;
            $bad['idempotency_key'] = Str::uuid()->toString();
            $bad[$field] = match ($field) {
                'mode' => 'road','depot_id' => $this->place,'vehicle_refs' => array_fill(0, 4, $fields['vehicle_refs'][0])
            };
            try {
                app(PlanRoutes::class)->create($this->admin, $bad);
                $this->fail('Invalid setup accepted');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }
        $this->rejected(fn () => app(PlanRoutes::class)->create($this->owner, $fields), 403);
    }
}
