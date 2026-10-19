<?php

namespace Tests\Feature;

use App\Actions\ManageOutputProfiles;
use App\Actions\MoveBatchOutputs;
use App\Support\Collection\Review;
use App\Support\Downstream\Context;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDOException;
use Tests\Support\DownstreamFixtures;
use Tests\TestCase;

class DownstreamConcurrencyTest extends TestCase
{
    use DownstreamFixtures;

    public function test_actual_root_commit_competition_retries_corrections_and_dependency_change(): void
    {
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1]);
        foreach (['reservation', 'canonical', 'retry', 'acknowledgment', 'dependency'] as $mode) {
            $schema = 'downstream_race_'.str_replace('-', '', Str::uuid()->toString());
            $c = config('database.connections.pgsql');
            $this->assertSame('ewaste_testing', $c['database']);
            $c['search_path'] = $schema;
            config(['database.connections.downstream_race' => $c]);
            DB::setDefaultConnection('downstream_race');
            Schema::clearResolvedInstance('db.schema');
            $file = tempnam(sys_get_temp_dir(), 'ewaste-downstream-race-');
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
                $w = $this->downstreamWork($mode === 'canonical');
                $h = $this->shipmentDraft($w);
                $childShipment = in_array($mode, ['reservation', 'canonical'], true) ? $this->shipmentDraft($w) : $h;
                $receiving = $mode === 'acknowledgment';
                $kind = $receiving ? 'accept' : 'reserve';
                if ($receiving) {
                    $this->downstreamRecord($w, 'reserve', $h);
                    $this->downstreamRecord($w, 'dispatch', $h);
                }
                $fields = $this->downstreamFields($w, $kind, $h, $receiving);
                $childFields = $mode === 'retry' ? $fields : $this->downstreamFields($w, $kind, $childShipment, $receiving);
                $actor = $receiving ? $w['recipient'] : $w['receiver'];
                DB::beginTransaction();
                if ($mode === 'dependency') {
                    Review::lock();
                    app(ManageOutputProfiles::class)->record($w['admin'], $w['profile'], array_replace($this->profileFields($w), ['approved' => '0']));
                    $winner = null;
                } else {
                    $winner = app(MoveBatchOutputs::class)->record($actor, $w['batch'], $kind, $fields);
                }
                file_put_contents($file, json_encode(['schema' => $schema, 'actor' => $actor->id, 'batch' => $w['batch'], 'kind' => $kind, 'fields' => $childFields, 'winner' => $winner, 'retry' => $mode === 'retry', 'signal' => $file.'.ready'], JSON_THROW_ON_ERROR));
                $process = proc_open([PHP_BINARY, base_path('tests/Support/downstream-race-worker.php'), $file], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
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
                $this->assertTrue($waiting, 'Competing '.$mode.' must wait on a real PostgreSQL lock.');
                $this->assertSame('', stream_get_contents($pipes[1]));
                DB::commit();
                stream_set_blocking($pipes[1], true);
                $output = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $pipes = [];
                $this->assertSame(0, proc_close($process));
                $process = null;
                $this->assertSame('', $error);
                $this->assertSame($mode === 'retry' ? 'original_returned' : 'conflict_rejected', $output);
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                $this->assertDatabaseCount('batch_members', 1);
                $this->assertEquals($mode === 'dependency' ? 0 : ($receiving ? 0 : ($mode === 'canonical' ? 0.5 : 10)), app(Context::class)->stock($w['output'])['reserved']);
                $this->assertEquals($receiving ? 10 : 0, app(Context::class)->stock($w['output'])['accepted']);
                if ($mode === 'retry') {
                    $this->commitFailureCleansOnlyNewFile($w);
                }
            } finally {
                if (is_resource($process)) {
                    proc_terminate($process);
                    foreach ($pipes as $p) {
                        if (is_resource($p)) {
                            fclose($p);
                        }
                    }proc_close($process);
                }
                while (DB::connection('downstream_race')->transactionLevel() > 0) {
                    DB::connection('downstream_race')->rollBack();
                }
                DB::connection('downstream_race')->statement('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
                DB::setDefaultConnection('pgsql');
                Schema::clearResolvedInstance('db.schema');
                DB::purge('downstream_race');
                @unlink($file);
                @unlink($file.'.ready');
            }
            $this->assertFalse(DB::table('pg_namespace')->where('nspname', $schema)->exists());
        }
    }

    private function commitFailureCleansOnlyNewFile(array $w): void
    {
        $this->assertSame(0, DB::transactionLevel());
        Storage::fake('directory_evidence');
        Storage::disk('directory_evidence')->put('retained.txt', 'Synthetic retained evidence');
        $before = [];
        foreach (['subjects', 'items', 'custody_current', 'dismantling_batches', 'batch_records', 'batch_members', 'batch_detachments', 'batch_outputs', 'downstream_records', 'downstream_batches', 'downstream_shipments', 'commands', 'domain_events', 'attachments'] as $t) {
            $before[$t] = DB::table($t)->get()->toJson();
        }
        DB::unprepared("CREATE FUNCTION fail_downstream_commit_fixture() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Synthetic deferred downstream failure'; END; $$; CREATE CONSTRAINT TRIGGER fail_downstream_commit_fixture AFTER INSERT ON downstream_records DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fail_downstream_commit_fixture();");
        $f = $this->downstreamFields($w, 'measurement_dispute');
        $f['evidence_kind'] = 'attachment';
        unset($f['reference_issuer'],$f['reference_code'],$f['reference_attested']);
        try {
            app(MoveBatchOutputs::class)->record($w['receiver'], $w['batch'], 'measurement_dispute', $f, UploadedFile::fake()->image('private.png'));
            $this->fail('Root COMMIT must evaluate injected deferred failure');
        } catch (PDOException $e) {
            $this->assertStringContainsString('Synthetic deferred downstream failure', $e->getMessage());
        }
        foreach ($before as $t => $rows) {
            $this->assertSame($rows, DB::table($t)->get()->toJson(), $t);
        }$this->assertSame(['retained.txt'], Storage::disk('directory_evidence')->allFiles());
    }
}
