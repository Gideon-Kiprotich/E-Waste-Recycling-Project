<?php

namespace Tests\Feature;

use App\Actions\InspectItems;
use App\Actions\RecordSanitization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDOException;
use Tests\Support\SanitizationFixtures;
use Tests\TestCase;

class SanitizationConcurrencyTest extends TestCase
{
    use SanitizationFixtures;

    public function test_real_competing_assurance_stages_wait_for_commit_then_reject_stale_work_or_return_identical_retry(): void
    {
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1]);
        foreach (['attempt', 'verification', 'validation', 'revalidation', 'retry'] as $kind) {
            $schema = 'sanitization_race_'.str_replace('-', '', Str::uuid()->toString());
            $c = config('database.connections.pgsql');
            $this->assertSame('ewaste_testing', $c['database']);
            $c['search_path'] = $schema;
            config(['database.connections.sanitization_race' => $c]);
            DB::setDefaultConnection('sanitization_race');
            Schema::clearResolvedInstance('db.schema');
            $file = tempnam(sys_get_temp_dir(), 'ewaste-sanitization-race-');
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
                $subject = $w['ids'][0];
                $stage = $kind === 'retry' ? 'attempt' : $kind;
                if (in_array($stage, ['verification', 'validation', 'revalidation'], true)) {
                    $this->mediaRecord($w);
                }
                if (in_array($stage, ['validation', 'revalidation'], true)) {
                    $this->mediaRecord($w, 'verification');
                }
                if ($stage === 'revalidation') {
                    $this->mediaRecord($w, 'validation');
                    app(InspectItems::class)->decide($w['receiver'], $subject, $this->decisionFields($subject, 'recycle'));
                }
                $fields = $this->mediaFields($w, $stage);
                $child = $kind === 'retry' ? $fields : array_replace($fields, ['idempotency_key' => Str::uuid()->toString()]);
                DB::beginTransaction();
                $winner = app(RecordSanitization::class)->record($w['receiver'], $subject, $stage, $fields);
                file_put_contents($file, json_encode(['schema' => $schema, 'kind' => $stage, 'actor' => $w['receiver']->id, 'subject' => $subject, 'fields' => $child, 'winner' => $winner, 'retry' => $kind === 'retry', 'signal' => $file.'.ready'], JSON_THROW_ON_ERROR));
                $process = proc_open([PHP_BINARY, base_path('tests/Support/sanitization-race-worker.php'), $file], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
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
                $this->assertTrue($waiting, 'Competing '.$kind.' must wait on a real PostgreSQL lock.');
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
                $expected = ['attempt' => 1, 'verification' => 2, 'validation' => 3, 'revalidation' => 4, 'retry' => 1][$kind];
                $this->assertDatabaseCount('sanitization_records', $expected);
                $this->assertSame($expected, DB::table('sanitization_current')->value('version'));
                $this->assertSame($winner, DB::table('sanitization_current')->value('record_id'));
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                $this->assertSame(3, DB::table('custody_current')->where('subject_id', $subject)->value('version'));
                $this->assertDatabaseCount('first_receipts', 1);
                $this->assertDatabaseCount('handover_receipts', 1);
                if ($kind === 'retry') {
                    $this->assertCommitFailureCleansNewEvidence($w);
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
                while (DB::connection('sanitization_race')->transactionLevel() > 0) {
                    DB::connection('sanitization_race')->rollBack();
                }
                DB::connection('sanitization_race')->statement('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
                DB::setDefaultConnection('pgsql');
                Schema::clearResolvedInstance('db.schema');
                DB::purge('sanitization_race');
                @unlink($file);
                @unlink($file.'.ready');
            }
            $this->assertFalse(DB::table('pg_namespace')->where('nspname', $schema)->exists());
        }
    }

    private function assertCommitFailureCleansNewEvidence(array $w): void
    {
        $this->assertSame(0, DB::transactionLevel());
        Storage::fake('directory_evidence');
        Storage::disk('directory_evidence')->put('retained.txt', 'Synthetic retained evidence');
        $before = [];
        foreach (['sanitization_records', 'sanitization_current', 'attachments', 'commands', 'domain_events'] as $t) {
            $before[$t] = DB::table($t)->get()->toJson();
        }
        DB::unprepared("CREATE FUNCTION fail_media_commit_fixture() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Synthetic deferred commit failure'; END; $$; CREATE CONSTRAINT TRIGGER fail_media_commit_fixture AFTER UPDATE ON sanitization_current DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fail_media_commit_fixture();");
        $f = $this->mediaFields($w);
        $f['evidence_kind'] = 'attachment';
        unset($f['reference_issuer'], $f['reference_code'], $f['reference_attested']);
        try {
            app(RecordSanitization::class)->record($w['receiver'], $w['ids'][0], 'attempt', $f, UploadedFile::fake()->image('private.png'));
            $this->fail('Actual root transaction commit must evaluate the injected deferred trigger.');
        } catch (PDOException $e) {
            $this->assertStringContainsString('Synthetic deferred commit failure', $e->getMessage());
        }
        foreach ($before as $t => $rows) {
            $this->assertSame($rows, DB::table($t)->get()->toJson(), $t);
        }
        $this->assertSame(['retained.txt'], Storage::disk('directory_evidence')->allFiles());
    }
}
