<?php

namespace Tests\Feature;

use App\Actions\ManageCollector;
use App\Actions\ManageVehicle;
use App\Actions\PlanRoutes;
use App\Models\User;
use App\Support\Routing\Planner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\RoutingFixtures;
use Tests\Support\RoutingScenarios;
use Tests\TestCase;

class RoutingEvaluationTest extends TestCase
{
    use RoutingFixtures;

    public function test_all_frozen_paired_scenarios_and_one_factor_sensitivities(): void
    {
        $planner = new Planner;
        $inputs = RoutingScenarios::all();
        $this->assertCount(30, $inputs);
        $paired = $sensitivity = [];
        foreach ($inputs as $s) {
            $result = $planner->compare($s);
            $repeat = $planner->compare($s);
            foreach (['baseline', 'proposed'] as $method) {
                $this->assertSame($result[$method], $repeat[$method]);
                $this->assertSame(0, $result[$method]['constraint_violations']);
                $this->assertSame($s['submitted'], count($s['excluded']) + count($result[$method]['served']) + count($result[$method]['unserved']));
            }
            $paired[] = ['scenario' => $s['name'], 'input_sha256' => hash('sha256', json_encode($s)), 'result' => $result];
            foreach (RoutingScenarios::variations($s) as $v) {
                $r = $planner->compare($v['snapshot']);
                foreach (['baseline', 'proposed'] as $m) {
                    $planner->validate($v['snapshot'], $r[$m]);
                    $this->assertSame(0, $r[$m]['constraint_violations']);
                }$sensitivity[] = ['scenario' => $s['name'], 'parameter' => $v['parameter'], 'value' => $v['value'], 'result' => $r];
            }
        }
        $this->assertCount(240, $sensitivity);
        RoutingScenarios::export('inputs.jsonl', $inputs);
        RoutingScenarios::export('paired-results.jsonl', $paired);
        RoutingScenarios::export('sensitivity-results.jsonl', $sensitivity);
    }

    public function test_ten_full_local_capture_calculate_validate_persist_benchmarks_per_size(): void
    {
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1]);
        $schema = 'routing_benchmark_'.str_replace('-', '', Str::uuid()->toString());
        $c = config('database.connections.pgsql');
        $this->assertSame('ewaste_testing', $c['database']);
        $c['search_path'] = $schema;
        config(['database.connections.routing_benchmark' => $c]);
        DB::setDefaultConnection('routing_benchmark');
        Schema::clearResolvedInstance('db.schema');
        $rows = [];
        $benchmarkInputs = [];
        try {
            DB::statement('CREATE SCHEMA "'.$schema.'"');
            $files = glob(database_path('migrations/*.php'));
            sort($files);
            foreach ($files as $m) {
                DB::transaction(fn () => (require $m)->up());
            }
            [$admin,$owner,$collector,$place,$depot,$vehicle] = $this->routingSetup();
            $collectors = [$collector];
            $vehicles = [$vehicle];
            $org = DB::table('vehicles')->where('id', $vehicle)->value('organization_id');
            foreach ([200, 300] as $capacity) {
                $other = User::factory()->create();
                $other->membership()->update(['role' => 'collector']);
                app(ManageCollector::class)->save($admin, $other->id, ['operation' => 'link', 'organization_ref' => $org.':'.DB::table('organizations')->where('id', $org)->value('version'), 'expected_version' => 1, 'reason' => 'Synthetic benchmark mapping', 'idempotency_key' => Str::uuid()->toString()]);
                $collectors[] = $other->refresh();
                $vehicles[] = app(ManageVehicle::class)->save($admin, null, ['organization_id' => $org, 'collector_id' => $other->id, 'code' => 'SYNTHETIC-'.$capacity, 'synthetic' => '1', 'capacity_kg' => (string) $capacity, 'max_duration_seconds' => 21600, 'available' => '1', 'idempotency_key' => Str::uuid()->toString()]);
            }
            $ids = [];
            // Real guarded domain fixtures exercise the application path; these are not field measurements.
            for ($i = 0; $i < 50; $i++) {
                $a = $this->accepted($admin, $owner, $collectors[$i % 3], $place);
                $request = DB::table('assignments')->where('id', $a)->value('request_id');
                $private = DB::table('requests')->where('id', $request)->value('private_details_id');
                // Set the synthetic location before freezing; location is intentionally absent from Phase 5's binding.
                DB::table('request_private_details')->where('id', $private)->update(['latitude' => -1.34 + 0.0025 * $i, 'longitude' => 36.76 + 0.0025 * $i]);
                $ids[] = $a;
            }
            foreach ([10, 25, 50] as $size) {
                for ($repeat = 1; $repeat <= 10; $repeat++) {
                    $fields = $this->routeFields($depot, $vehicle, array_slice($ids, 0, $size));
                    $fields['vehicle_refs'] = array_map(fn ($id) => $id.':1', $vehicles);
                    $start = hrtime(true);
                    $id = app(PlanRoutes::class)->create($admin, $fields);
                    $seconds = (hrtime(true) - $start) / 1e9;
                    $record = DB::table('route_runs')->where('id', $id)->first();
                    $result = json_decode($record->results, true, flags: JSON_THROW_ON_ERROR);
                    $this->assertSame(0, DB::transactionLevel());
                    $this->assertCount($size, PlanRoutes::snapshot($record)['jobs']);
                    $this->assertLessThanOrEqual(10, $seconds, 'NF04 local planning/validation/persistence budget');
                    $this->assertFalse($result['proposed']['budget_exhausted']);
                    if ($repeat === 1) {
                        $input = PlanRoutes::snapshot($record);
                        $benchmarkInputs[] = ['size' => $size, 'jobs' => $input['jobs'], 'vehicles' => $input['vehicles'], 'depot' => $input['depot'], 'matrix' => $input['matrix'], 'source' => $input['source']];
                    }
                    $rows[] = ['size' => $size, 'repeat' => $repeat, 'seconds' => $seconds, 'baseline_served' => count($result['baseline']['served']), 'proposed_served' => count($result['proposed']['served']), 'budget_exhausted' => $result['proposed']['budget_exhausted']];
                }
            }
            RoutingScenarios::export('benchmarks.jsonl', $rows);
            RoutingScenarios::export('benchmark-inputs.jsonl', $benchmarkInputs);
        } finally {
            while (DB::connection('routing_benchmark')->transactionLevel() > 0) {
                DB::connection('routing_benchmark')->rollBack();
            }DB::connection('routing_benchmark')->statement('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
            DB::setDefaultConnection('pgsql');
            Schema::clearResolvedInstance('db.schema');
            DB::purge('routing_benchmark');
        }
        $this->assertFalse(DB::table('pg_namespace')->where('nspname', $schema)->exists());
    }
}
