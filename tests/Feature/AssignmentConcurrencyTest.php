<?php

namespace Tests\Feature;

use App\Actions\AllocateCollector;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\AssignmentFixtures;
use Tests\TestCase;

class AssignmentConcurrencyTest extends TestCase
{
    use AssignmentFixtures;

    public function test_real_competing_offers_acceptance_reassignment_and_direct_insert_conflicts(): void
    {
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1]);
        foreach (['offer', 'accept', 'reassign', 'direct'] as $operation) {
            $schema = 'assignment_race_'.str_replace('-', '', Str::uuid()->toString());
            $c = config('database.connections.pgsql');
            $this->assertSame('ewaste_testing', $c['database']);
            $c['search_path'] = $schema;
            config(['database.connections.assignment_race' => $c]);
            DB::setDefaultConnection('assignment_race');
            Schema::clearResolvedInstance('db.schema');
            $file = tempnam(sys_get_temp_dir(), 'ewaste-assignment-race-');
            chmod($file, 0600);
            $process = null;
            $pipes = [];
            try {
                DB::statement('CREATE SCHEMA "'.$schema.'"');
                $files = glob(database_path('migrations/*.php'));
                sort($files);
                foreach ($files as $migration) {
                    DB::transaction(fn () => (require $migration)->up());
                }
                [$admin, $owner, $collector, $place] = $this->allocationSetup();
                $other = User::factory()->create();
                $other->membership()->update(['role' => Role::Administrator]);
                $other->refresh();
                $request = $this->readyRequest($admin, $owner);
                $offer = $this->offerFields($request, $collector, $place);
                $id = null;
                if ($operation !== 'offer') {
                    $id = app(AllocateCollector::class)->allocate($admin, $request, $offer);
                }
                $replacement = $this->offerFields($request, $collector, $place) + ['assignment_id' => $id, 'expected_version' => 1];
                $replacement['operation'] = 'reassign';
                $response = $id ? $this->responseFields($id) : [];
                $fields = match ($operation) {
                    'offer' => $this->offerFields($request, $collector, $place),
                    'accept' => $response,
                    'reassign' => $replacement,
                    'direct' => array_replace((array) DB::table('assignments')->where('id', $id)->first(), ['id' => Str::uuid()->toString()]),
                };
                if ($operation === 'direct') {
                    app(AllocateCollector::class)->allocate($admin, $request, ['operation' => 'withdraw', 'assignment_id' => $id, 'expected_version' => 1, 'reason' => 'Synthetic direct index fixture', 'idempotency_key' => Str::uuid()->toString()]);
                }
                $beforeCustody = DB::table('custody_current')->get()->toJson();
                $beforeReservations = DB::table('request_items')->get()->toJson();
                file_put_contents($file, json_encode(['schema' => $schema, 'actor' => $operation === 'accept' ? $collector->id : $other->id, 'operation' => $operation, 'id' => $operation === 'accept' ? $id : $request, 'fields' => $fields, 'signal' => $file.'.ready'], JSON_THROW_ON_ERROR));
                DB::beginTransaction();
                match ($operation) {
                    'offer' => app(AllocateCollector::class)->allocate($admin, $request, $offer),
                    'accept' => app(AllocateCollector::class)->allocate($admin, $request, $replacement),
                    'reassign' => app(AllocateCollector::class)->respond($collector, $id, $response),
                    'direct' => DB::table('assignments')->insert(array_replace($fields, ['id' => Str::uuid()->toString()])),
                };
                $process = proc_open([PHP_BINARY, base_path('tests/Support/assignment-race-worker.php'), $file], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                fclose($pipes[0]);
                stream_set_blocking($pipes[1], false);
                $waiting = false;
                $deadline = microtime(true) + 10;
                do {
                    if (is_file($file.'.ready')) {
                        $state = DB::connection('pgsql')->selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid=?', [(int) file_get_contents($file.'.ready')]);
                        $waiting = $state?->wait_event_type === 'Lock';
                    }
                    if ($waiting) {
                        break;
                    }
                    usleep(10000);
                } while (microtime(true) < $deadline);
                $this->assertTrue($waiting, $operation.' must reach a real PostgreSQL lock.');
                $this->assertSame('', stream_get_contents($pipes[1]));
                DB::commit();
                stream_set_blocking($pipes[1], true);
                stream_set_timeout($pipes[1], 10);
                $this->assertSame('conflict_rejected', stream_get_contents($pipes[1]));
                fclose($pipes[1]);
                fclose($pipes[2]);
                $pipes = [];
                $this->assertSame(0, proc_close($process));
                $process = null;
                $this->assertSame(1, DB::table('assignments')->where('request_id', $request)->where('active', true)->count());
                $this->assertSame($beforeCustody, DB::table('custody_current')->get()->toJson());
                $this->assertSame($beforeReservations, DB::table('request_items')->get()->toJson());
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            } finally {
                if (is_resource($process)) {
                    proc_terminate($process);
                    foreach ($pipes as $pipe) {
                        if (is_resource($pipe)) {
                            fclose($pipe);
                        }
                    }
                    proc_close($process);
                }
                while (DB::connection('assignment_race')->transactionLevel() > 0) {
                    DB::connection('assignment_race')->rollBack();
                }
                DB::connection('assignment_race')->statement('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
                DB::setDefaultConnection('pgsql');
                Schema::clearResolvedInstance('db.schema');
                DB::purge('assignment_race');
                if (is_file($file)) {
                    unlink($file);
                }
                if (is_file($file.'.ready')) {
                    unlink($file.'.ready');
                }
            }
            $this->assertFalse(DB::table('pg_namespace')->where('nspname', $schema)->exists());
        }
    }
}
