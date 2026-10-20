<?php

namespace Tests\Feature;

use App\Actions\InspectItems;
use App\Actions\ReviewRestrictions;
use App\Support\Collection\AdminMutation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDOException;
use Tests\Support\RestrictionFixtures;
use Tests\TestCase;

class RestrictionConcurrencyTest extends TestCase
{
    use RestrictionFixtures;

    public function test_real_root_competing_proposals_approvals_retries_and_revocation(): void
    {
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1]);
        foreach (['proposal', 'approve', 'retry', 'revoke', 'source_change'] as $mode) {
            $schema = 'restriction_race_'.str_replace('-', '', Str::uuid()->toString());
            $config = config('database.connections.pgsql');
            $this->assertSame('ewaste_testing', $config['database']);
            $config['search_path'] = $schema;
            config(['database.connections.restriction_race' => $config]);
            DB::setDefaultConnection('restriction_race');
            Schema::clearResolvedInstance('db.schema');
            $file = tempnam(sys_get_temp_dir(), 'ewaste-restriction-race-');
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
                $w = $mode === 'source_change' ? $this->restrictionWork('inspection', 'safety_concern') : $this->restrictionWork();
                $case = $this->openRestriction($w);
                $this->restrictionRecord($w, $case, 'assign');
                if (in_array($mode, ['approve', 'source_change'], true)) {
                    $this->restrictionRecord($w, $case, 'proposal');
                }
                $kind = in_array($mode, ['approve', 'source_change'], true) ? 'approve' : 'proposal';
                $actor = $kind === 'approve' ? $w['admin'] : $w['investigator'];
                $fields = $this->restrictionFields($w, $kind, $case, $actor);
                $childFields = $mode === 'retry' ? $fields : array_replace($fields, ['idempotency_key' => Str::uuid()->toString()]);
                DB::beginTransaction();
                if ($mode === 'source_change') {
                    $inspection = $this->inspectionFields($w['subject']);
                    $inspection['answers']['thermal_issue'] = 'YES';
                    $winner = app(InspectItems::class)->inspect($w['investigator'], $w['subject'], $inspection);
                } elseif ($mode === 'revoke') {
                    $winner = app(ReviewRestrictions::class)->record($w['admin'], $case, 'revoke', $this->restrictionFields($w, 'revoke', $case));
                } else {
                    $winner = app(ReviewRestrictions::class)->record($actor, $case, $kind, $fields);
                }
                file_put_contents($file, json_encode(['schema' => $schema, 'actor' => $actor->id, 'case' => $case, 'kind' => $kind, 'fields' => $childFields, 'retry' => $mode === 'retry', 'winner' => $winner, 'signal' => $file.'.ready'], JSON_THROW_ON_ERROR));
                $process = proc_open([PHP_BINARY, base_path('tests/Support/restriction-race-worker.php'), $file], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
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
                $this->assertTrue($waiting, 'Competing '.$mode.' must wait on an actual PostgreSQL lock.');
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
                $this->assertSame($mode === 'approve' ? 1 : 0, DB::table('restriction_supersessions')->count());
                $this->assertDatabaseCount('first_receipts', $mode === 'source_change' ? 1 : 0);
                if ($mode === 'retry') {
                    $this->rootFailureCompensatesEvidence($w, $case);
                }
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
                while (DB::connection('restriction_race')->transactionLevel() > 0) {
                    DB::connection('restriction_race')->rollBack();
                }
                DB::connection('restriction_race')->statement('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
                DB::setDefaultConnection('pgsql');
                Schema::clearResolvedInstance('db.schema');
                DB::purge('restriction_race');
                @unlink($file);
                @unlink($file.'.ready');
            }
            $this->assertFalse(DB::table('pg_namespace')->where('nspname', $schema)->exists());
        }
    }

    private function rootFailureCompensatesEvidence(array $w, string $case): void
    {
        $this->assertSame(0, DB::transactionLevel());
        Storage::fake('directory_evidence');
        Storage::disk('directory_evidence')->put('retained.txt', 'Synthetic retained file');
        $before = [];
        foreach (['restriction_cases', 'restriction_records', 'restriction_supersessions', 'restriction_epochs', 'restriction_epoch_steps', 'restriction_impacts', 'attachments', 'commands', 'domain_events', 'notification_outbox', 'custody_current', 'first_receipts'] as $table) {
            $before[$table] = DB::table($table)->get()->toJson();
        }
        DB::unprepared("CREATE FUNCTION fail_restriction_commit_fixture() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Synthetic deferred restriction failure'; END; $$; CREATE CONSTRAINT TRIGGER fail_restriction_commit_fixture AFTER INSERT ON restriction_records DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fail_restriction_commit_fixture();");
        $fields = $this->restrictionFields($w, 'approve', $case);
        $fields['evidence_kind'] = 'attachment';
        unset($fields['reference_issuer'], $fields['reference_code'], $fields['reference_attested']);
        try {
            app(ReviewRestrictions::class)->record($w['admin'], $case, 'approve', $fields, UploadedFile::fake()->image('private.png'));
            $this->fail('Root COMMIT must evaluate the injected deferred failure.');
        } catch (PDOException $e) {
            $this->assertStringContainsString('Synthetic deferred restriction failure', $e->getMessage());
        }
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->get()->toJson(), $table);
        }
        $this->assertSame(['retained.txt'], Storage::disk('directory_evidence')->allFiles());
        DB::unprepared('DROP TRIGGER fail_restriction_commit_fixture ON restriction_records; DROP FUNCTION fail_restriction_commit_fixture()');
        $commands = DB::table('commands')->count();
        try {
            DB::beginTransaction();
            AdminMutation::command($w['admin'], 'restriction_note:'.$case, Str::uuid()->toString(), AdminMutation::hash([]), $case);
            DB::commit();
            $this->fail('A direct orphan command must fail at root COMMIT.');
        } catch (PDOException $e) {
            $this->assertStringContainsString('reciprocal record', $e->getMessage());
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        $this->assertSame($commands, DB::table('commands')->count());
    }
}
