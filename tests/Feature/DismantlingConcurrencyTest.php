<?php

namespace Tests\Feature;

use App\Actions\DismantleBatches;
use App\Support\Batches\Context;
use App\Support\Collection\Review;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDOException;
use Tests\Support\BatchFixtures;
use Tests\TestCase;

class DismantlingConcurrencyTest extends TestCase
{
    use BatchFixtures;

    public function test_actual_root_commit_competition_retries_corrections_and_dependency_change(): void
    {
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1]);
        foreach (['competing_batch', 'retry', 'correction', 'dependency'] as $mode) {
            $schema = 'batch_race_'.str_replace('-', '', Str::uuid()->toString());
            $c = config('database.connections.pgsql');
            $this->assertSame('ewaste_testing', $c['database']);
            $c['search_path'] = $schema;
            config(['database.connections.batch_race' => $c]);
            DB::setDefaultConnection('batch_race');
            Schema::clearResolvedInstance('db.schema');
            $file = tempnam(sys_get_temp_dir(), 'ewaste-batch-race-');
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
                $w = $this->batchWork();
                $this->batchRecord($w);
                $child = $w;
                $kind = $mode === 'correction' ? 'measurement' : 'commit';
                if ($mode === 'competing_batch') {
                    $child['batch'] = Str::uuid()->toString();
                    $this->batchRecord($child);
                }
                if ($mode === 'correction') {
                    $this->batchRecord($w, 'commit');
                }
                $fields = $this->batchFields($w, $kind);
                $childFields = $mode === 'competing_batch' ? $this->batchFields($child, $kind) : $fields;
                if (in_array($mode, ['competing_batch', 'correction'], true)) {
                    $childFields['idempotency_key'] = Str::uuid()->toString();
                }
                DB::beginTransaction();
                if ($mode === 'dependency') {
                    Review::lock();
                    $place = DB::table('parties')->where('id', app(Context::class)->party($w['receiver']->fresh()))->value('place_id');
                    DB::table('place_categories')->where('place_id', $place)->delete();
                    $winner = null;
                } else {
                    $winner = app(DismantleBatches::class)->record($w['receiver'], $w['batch'], $kind, $fields);
                }
                file_put_contents($file, json_encode(['schema' => $schema, 'actor' => $w['receiver']->id, 'batch' => $child['batch'], 'kind' => $kind, 'fields' => $childFields, 'winner' => $winner, 'retry' => $mode === 'retry', 'signal' => $file.'.ready'], JSON_THROW_ON_ERROR));
                $process = proc_open([PHP_BINARY, base_path('tests/Support/batch-race-worker.php'), $file], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
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
                $this->assertDatabaseCount('batch_members', $mode === 'dependency' ? 0 : 1);
                $this->assertDatabaseCount('batch_detachments', $mode === 'dependency' ? 0 : 1);
                $this->assertDatabaseCount('first_receipts', 1);
                $this->assertDatabaseCount('handover_receipts', 1);
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
                while (DB::connection('batch_race')->transactionLevel() > 0) {
                    DB::connection('batch_race')->rollBack();
                }
                DB::connection('batch_race')->statement('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
                DB::setDefaultConnection('pgsql');
                Schema::clearResolvedInstance('db.schema');
                DB::purge('batch_race');
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
        foreach (['subjects', 'items', 'custody_current', 'dismantling_batches', 'batch_records', 'batch_members', 'batch_detachments', 'batch_outputs', 'commands', 'domain_events', 'attachments'] as $t) {
            $before[$t] = DB::table($t)->get()->toJson();
        }
        DB::unprepared("CREATE FUNCTION fail_batch_commit_fixture() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Synthetic deferred batch failure'; END; $$; CREATE CONSTRAINT TRIGGER fail_batch_commit_fixture AFTER UPDATE ON dismantling_batches DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fail_batch_commit_fixture();");
        $f = $this->batchFields($w, 'output');
        $f['evidence_kind'] = 'attachment';
        unset($f['reference_issuer'],$f['reference_code'],$f['reference_attested']);
        try {
            app(DismantleBatches::class)->record($w['receiver'], $w['batch'], 'output', $f, UploadedFile::fake()->image('private.png'));
            $this->fail('Root COMMIT must evaluate injected deferred failure');
        } catch (PDOException $e) {
            $this->assertStringContainsString('Synthetic deferred batch failure', $e->getMessage());
        }
        foreach ($before as $t => $rows) {
            $this->assertSame($rows, DB::table($t)->get()->toJson(), $t);
        }$this->assertSame(['retained.txt'],Storage::disk('directory_evidence')->allFiles());
    }
}
