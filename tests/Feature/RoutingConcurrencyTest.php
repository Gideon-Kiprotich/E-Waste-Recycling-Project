<?php

namespace Tests\Feature;

use App\Actions\PlanRoutes;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\RoutingFixtures;
use Tests\TestCase;

class RoutingConcurrencyTest extends TestCase
{
    use RoutingFixtures;

    public function test_competing_approvals_and_direct_claims_reach_real_locks_and_reject_duplicates(): void
    {
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1]);
        foreach (['approve', 'direct'] as $operation) {
            $schema = 'routing_race_'.str_replace('-', '', Str::uuid()->toString());
            $c = config('database.connections.pgsql');
            $this->assertSame('ewaste_testing', $c['database']);
            $c['search_path'] = $schema;
            config(['database.connections.routing_race' => $c]);
            DB::setDefaultConnection('routing_race');
            Schema::clearResolvedInstance('db.schema');
            $file = tempnam(sys_get_temp_dir(), 'ewaste-route-race-');
            chmod($file, 0600);
            $process = null;
            $pipes = [];
            try {
                DB::statement('CREATE SCHEMA "'.$schema.'"');
                $files = glob(database_path('migrations/*.php'));
                sort($files);
                foreach ($files as $m) {
                    DB::transaction(fn () => (require $m)->up());
                }
                [$admin,$owner,$collector,$place,$depot,$vehicle] = $this->routingSetup();
                $a = $this->accepted($admin, $owner, $collector, $place);
                $fields = $this->routeFields($depot, $vehicle, [$a]);
                $first = app(PlanRoutes::class)->create($admin, $fields);
                $second = app(PlanRoutes::class)->create($admin, array_replace($fields, $this->approvalFields()));
                $other = User::factory()->create();
                $other->membership()->update(['role' => 'administrator']);
                $other->refresh();
                $before = DB::table('custody_current')->get()->toJson();
                $reservations = DB::table('request_items')->get()->toJson();
                $approval = Str::uuid()->toString();
                file_put_contents($file, json_encode(['schema' => $schema, 'operation' => $operation, 'actor' => $other->id, 'run' => $second, 'fields' => $this->approvalFields(), 'signal' => $file.'.ready', 'approval' => ['id' => $approval, 'run_id' => $second, 'approved_by' => $other->id, 'approved_at' => now()->toIso8601String(), 'state' => 'approved', 'version' => 1], 'claim' => ['approval_id' => $approval, 'kind' => 'assignment', 'record_id' => $a]], JSON_THROW_ON_ERROR));
                DB::beginTransaction();
                app(PlanRoutes::class)->approve($admin, $first, $this->approvalFields());
                $process = proc_open([PHP_BINARY, base_path('tests/Support/routing-race-worker.php'), $file], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                fclose($pipes[0]);
                stream_set_blocking($pipes[1], false);
                $waiting = false;
                $end = microtime(true) + 10;
                do {
                    if (is_file($file.'.ready')) {
                        $state = DB::connection('pgsql')->selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid=?', [(int) file_get_contents($file.'.ready')]);
                        $waiting = $state?->wait_event_type === 'Lock';
                    }if ($waiting) {
                        break;
                    }usleep(10000);
                } while (microtime(true) < $end);
                $this->assertTrue($waiting, 'Competing '.$operation.' must reach a PostgreSQL lock.');
                $this->assertSame('', stream_get_contents($pipes[1]));
                DB::commit();
                stream_set_blocking($pipes[1], true);
                stream_set_timeout($pipes[1], 15);
                $this->assertSame('conflict_rejected', stream_get_contents($pipes[1]));
                fclose($pipes[1]);
                fclose($pipes[2]);
                $pipes = [];
                $this->assertSame(0, proc_close($process));
                $process = null;
                $this->assertDatabaseCount('route_approvals', 1);
                $this->assertDatabaseCount('route_claims', 3);
                $this->assertSame($before, DB::table('custody_current')->get()->toJson());
                $this->assertSame($reservations, DB::table('request_items')->get()->toJson());
            } finally {
                if (is_resource($process)) {
                    proc_terminate($process);
                    foreach ($pipes as $p) {
                        if (is_resource($p)) {
                            fclose($p);
                        }
                    }proc_close($process);
                }while (DB::connection('routing_race')->transactionLevel() > 0) {
                    DB::connection('routing_race')->rollBack();
                }DB::connection('routing_race')->statement('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
                DB::setDefaultConnection('pgsql');
                Schema::clearResolvedInstance('db.schema');
                DB::purge('routing_race');
                @unlink($file);
                @unlink($file.'.ready');
            }
            $this->assertFalse(DB::table('pg_namespace')->where('nspname',$schema)->exists());
        }
    }
}
