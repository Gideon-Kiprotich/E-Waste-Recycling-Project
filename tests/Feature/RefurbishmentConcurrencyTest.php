<?php

namespace Tests\Feature;

use App\Actions\RecordSanitization;
use App\Actions\RefurbishItems;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDOException;
use Tests\Support\RefurbishmentFixtures;
use Tests\TestCase;

class RefurbishmentConcurrencyTest extends TestCase
{
    use RefurbishmentFixtures;

    public function test_real_acknowledgment_withdrawal_dependency_races_and_commit_compensation(): void
    {
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1]);
        foreach (['acknowledge', 'retry', 'withdraw', 'dependency', 'withdraw_after_ack'] as $kind) {
            $schema = 'reuse_race_'.str_replace('-', '', Str::uuid()->toString());
            $c = config('database.connections.pgsql');
            $this->assertSame('ewaste_testing', $c['database']);
            $c['search_path'] = $schema;
            config(['database.connections.reuse_race' => $c]);
            DB::setDefaultConnection('reuse_race');
            Schema::clearResolvedInstance('db.schema');
            $file = tempnam(sys_get_temp_dir(), 'ewaste-reuse-race-');
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
                $w = $this->mediaWork();
                $s = $w['ids'][0];
                $this->mediaSatisfied($w);
                $this->reusePassed($w);
                $this->reuseRecord($w, 'authorize');
                $ack = $this->reuseFields($w, 'acknowledge', true);
                $withdraw = $this->reuseFields($w, 'withdraw');
                $childKind = $kind === 'withdraw_after_ack' ? 'withdraw' : 'acknowledge';
                $child = $childKind === 'withdraw' ? $withdraw : $ack;
                if ($kind === 'acknowledge') {
                    $child['idempotency_key'] = Str::uuid()->toString();
                }
                DB::beginTransaction();
                if ($kind === 'withdraw') {
                    $winner = app(RefurbishItems::class)->record($w['receiver'], $s, 'withdraw', $withdraw);
                } elseif ($kind === 'dependency') {
                    $winner = app(RecordSanitization::class)->record($w['receiver'], $s, 'attempt', $this->mediaFields($w, 'attempt', 'failed'));
                } else {
                    $winner = app(RefurbishItems::class)->record($w['owner'], $s, 'acknowledge', $ack);
                }
                file_put_contents($file, json_encode(['schema' => $schema, 'actor' => ($childKind === 'withdraw' ? $w['receiver'] : $w['owner'])->id, 'subject' => $s, 'kind' => $childKind, 'fields' => $child, 'winner' => $winner, 'retry' => $kind === 'retry', 'signal' => $file.'.ready'], JSON_THROW_ON_ERROR));
                $process = proc_open([PHP_BINARY, base_path('tests/Support/reuse-race-worker.php'), $file], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                fclose($pipes[0]);
                stream_set_blocking($pipes[1], false);
                $waiting = false;
                $end = microtime(true) + 10;
                do {
                    if (is_file($file.'.ready')) {
                        $state = DB::connection('pgsql')->selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid=?', [(int) file_get_contents($file.'.ready')]);
                        $waiting = $state?->wait_event_type === 'Lock';
                    }
                    if ($waiting) {
                        break;
                    }
                    usleep(10000);
                } while (microtime(true) < $end);
                $this->assertTrue($waiting, 'Competing '.$kind.' must wait on an actual PostgreSQL lock.');
                $this->assertSame('', stream_get_contents($pipes[1]));
                DB::commit();
                stream_set_blocking($pipes[1], true);
                stream_set_timeout($pipes[1], 15);
                $this->assertSame($kind === 'retry' ? 'original_returned' : 'conflict_rejected', stream_get_contents($pipes[1]));
                fclose($pipes[1]);
                fclose($pipes[2]);
                $pipes = [];
                $this->assertSame(0, proc_close($process));
                $process = null;
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                $this->assertDatabaseCount('reuse_return_items', in_array($kind, ['withdraw', 'dependency'], true) ? 0 : 1);
                $this->assertDatabaseCount('first_receipts', 1);
                $this->assertDatabaseCount('handover_receipts', 1);
                if ($kind === 'withdraw') {
                    $this->assertCommitFailureCleansOnlyNewFile($w);
                }
            } finally {
                if (is_resource($process)) {
                    proc_terminate($process);
                    foreach ($pipes as $p) {
                        if (is_resource($p)) {
                            fclose($p);
                        }
                    }
                    proc_close($process);
                }
                while (DB::connection('reuse_race')->transactionLevel() > 0) {
                    DB::connection('reuse_race')->rollBack();
                }
                DB::connection('reuse_race')->statement('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
                DB::setDefaultConnection('pgsql');
                Schema::clearResolvedInstance('db.schema');
                DB::purge('reuse_race');
                @unlink($file);
                @unlink($file.'.ready');
            }
            $this->assertFalse(DB::table('pg_namespace')->where('nspname', $schema)->exists());
        }
    }

    private function assertCommitFailureCleansOnlyNewFile(array $w): void
    {
        $this->assertSame(0, DB::transactionLevel());
        Storage::fake('directory_evidence');
        Storage::disk('directory_evidence')->put('retained.txt', 'Synthetic retained evidence');
        $before = [];
        foreach (['refurbishment_records', 'refurbishment_current', 'reuse_authorizations', 'reuse_manifest', 'reuse_return_items', 'attachments', 'commands', 'domain_events', 'items', 'subjects', 'custody_current'] as $t) {
            $before[$t] = DB::table($t)->get()->toJson();
        }
        DB::unprepared("CREATE FUNCTION fail_reuse_commit_fixture() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Synthetic deferred reuse failure'; END; $$; CREATE CONSTRAINT TRIGGER fail_reuse_commit_fixture AFTER UPDATE ON refurbishment_current DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fail_reuse_commit_fixture();");
        $f = $this->reuseFields($w);
        $f['evidence_kind'] = 'attachment';
        unset($f['reference_issuer'],$f['reference_code'],$f['reference_attested']);
        try {
            app(RefurbishItems::class)->record($w['receiver'], $w['ids'][0], 'work', $f, UploadedFile::fake()->image('private.png'));
            $this->fail('Root COMMIT must evaluate the injected deferred failure');
        } catch (PDOException $e) {
            $this->assertStringContainsString('Synthetic deferred reuse failure', $e->getMessage());
        }
        foreach ($before as $t => $rows) {
            $this->assertSame($rows, DB::table($t)->get()->toJson(), $t);
        }
        $this->assertSame(['retained.txt'], Storage::disk('directory_evidence')->allFiles());
    }
}
