<?php

namespace Tests\Feature;

use App\Actions\AcceptDownstreamResponsibility;
use App\Actions\ManagePointStaff;
use App\Actions\MoveBatchOutputs;
use App\Actions\ReviewRestrictions;
use App\Support\Collection\Review;
use App\Support\Restrictions\Context;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDOException;
use Tests\Support\DownstreamCorrectionFixtures;
use Tests\TestCase;

class DownstreamCorrectionConcurrencyTest extends TestCase
{
    use DownstreamCorrectionFixtures;

    public function test_real_competing_reviews_reservations_receipts_successors_and_retries(): void
    {
        $this->runRaces(['approve', 'retry', 'reserve', 'accept', 'recipient', 'revocation']);
    }

    public function test_corrections_serialize_against_dispatch_completion_and_source_loss(): void
    {
        $this->runRaces(['dispatch', 'complete', 'source_loss']);
    }

    private function runRaces(array $modes): void
    {
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1]);
        foreach ($modes as $mode) {
            $schema = 'downstream_correction_race_'.str_replace('-', '', (string) Str::uuid());
            $config = config('database.connections.pgsql');
            $this->assertSame('ewaste_testing', $config['database']);
            $config['search_path'] = $schema;
            config(['database.connections.correction_race' => $config]);
            DB::setDefaultConnection('correction_race');
            Schema::clearResolvedInstance('db.schema');
            $file = tempnam(sys_get_temp_dir(), 'ewaste-correction-');
            chmod($file, 0600);
            $process = null;
            $pipes = [];
            try {
                DB::statement('CREATE SCHEMA "'.$schema.'"');
                foreach (glob(database_path('migrations/*.php')) as $migration) {
                    DB::transaction(fn () => (require $migration)->up());
                }
                $w = $this->downstreamWork();
                $h = $this->shipmentDraft($w, $mode === 'dispatch' ? ['kg' => '5.000'] : []);
                $successor = in_array($mode, ['recipient', 'revocation'], true) ? $this->pointStaff($w['admin'], $w['destination']) : null;
                if ($successor) {
                    $this->handoverBoundary();
                }
                $measurement = in_array($mode, ['reserve', 'source_loss'], true);
                if (! $measurement) {
                    $this->downstreamRecord($w, 'reserve', $h, overrides: $mode === 'dispatch' ? ['kg' => '5.000'] : []);
                    if ($mode !== 'dispatch') {
                        $this->downstreamRecord($w, 'dispatch', $h);
                    }
                }
                if ($successor) {
                    $source = DB::table('downstream_shipments')->where('id', $h)->value('created_record_id');
                    $case = $this->correction($w, $source, 'recipient', [], false, extra: ['receiver_actor_id' => $successor->id, 'profile_record_id' => $w['profile_record']]);
                    $service = app(AcceptDownstreamResponsibility::class);
                    $fields = $this->handoverEvidence() + ['attested' => '1', 'expected_version' => 1, 'expected_context' => $service->token($successor, $h), 'reason' => 'Synthetic independently accepted responsibility'];
                    $actor = $successor;
                    $action = 'recipient';
                } else {
                    if ($measurement) {
                        $source = DB::table('batch_outputs')->where('subject_id', $w['output'])->value('measurement_record_id');
                        $input = DB::table('dismantling_batches')->where('subject_id', $w['batch'])->value('input_measurement_id');
                        $case = $this->correction($w, $source, 'measurement', [$this->fact('batch', $source, 'net_kg', '11.000'), $this->fact('batch', $input, 'net_kg', '11.000')], false, ['original_error' => 'YES', 'same_allocation' => 'YES', 'present_unallocated' => 'YES'], approve: false);
                    } elseif ($mode === 'dispatch') {
                        $source = $this->downstreamRecord($w, 'source_loss', overrides: ['kg' => '1.000']);
                        $case = $this->correction($w, $source, 'loss_confirm', [$this->fact('downstream', $source, 'loss_status', 'confirmed')], false, ['quantified_loss' => 'YES'], approve: false);
                    } elseif ($mode === 'complete') {
                        $accept = $this->downstreamRecord($w, 'accept', $h, true);
                        $source = DB::table('batch_outputs')->where('subject_id', $w['output'])->value('measurement_record_id');
                        $input = DB::table('dismantling_batches')->where('subject_id', $w['batch'])->value('input_measurement_id');
                        $revisions = [$this->fact('batch', $source, 'net_kg', '9.900'), $this->fact('batch', $input, 'net_kg', '9.900')];
                        foreach (DB::table('downstream_records')->where('shipment_id', $h)->whereIn('kind', ['reserve', 'dispatch', 'accept'])->pluck('id') as $allocation) {
                            $revisions[] = $this->fact('downstream', $allocation, 'kg', '9.900');
                        }
                        $case = $this->correction($w, $source, 'measurement', $revisions, false, ['original_error' => 'YES', 'same_allocation' => 'YES'], both: true, approve: false);
                    } else {
                        $source = $this->downstreamRecord($w, 'shortage', $h, true);
                        $case = $this->correction($w, $source, 'report_error', [$this->fact('downstream', $source, 'active', '0')], facts: ['original_error' => 'YES'], approve: false);
                    }
                    $type = match ($mode) {
                        'reserve', 'source_loss', 'complete' => 'measurement',
                        'dispatch' => 'loss_confirm',
                        default => 'report_error',
                    };
                    $receiving = in_array($mode, ['accept', 'complete'], true);
                    $rw = array_replace($w, ['subject' => $w['batch'], 'investigator' => in_array($type, ['measurement', 'loss_confirm'], true) ? $w['receiver'] : $w['recipient'], 'source' => app(Context::class)->source('downstream_correction', $source, 'downstream_'.$type)]);
                    $approval = $this->restrictionFields($rw, 'approve', $case);
                    $action = in_array($mode, ['reserve', 'accept', 'dispatch', 'complete', 'source_loss'], true) ? $mode : 'review';
                    $actor = $action === 'review' ? $w['admin'] : ($receiving ? $w['recipient'] : $w['receiver']);
                    $fields = $action === 'review' ? $approval : $this->downstreamFields($w, $action, $action === 'source_loss' ? null : $h, $receiving);
                    if (in_array($mode, ['dispatch', 'source_loss'], true)) {
                        $fields['kg'] = $mode === 'dispatch' ? '5.000' : '1.000';
                    }
                }
                if (in_array($mode, ['dispatch', 'complete', 'source_loss'], true)) {
                    $this->assertSame(app(\App\Support\Downstream\Context::class)->token($w['batch'], $actor->fresh(), $mode === 'source_loss' ? null : $h), $fields['expected_context']);
                }
                if (in_array($mode, ['complete', 'source_loss'], true)) {
                    // Prove the original request is valid before the competing correction commits.
                    $before = DB::table('downstream_records')->count();
                    DB::beginTransaction();
                    try {
                        app(MoveBatchOutputs::class)->record($actor, $w['batch'], $action, $fields);
                        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                    } finally {
                        DB::rollBack();
                    }
                    $this->assertSame($before, DB::table('downstream_records')->count());
                }
                $child = $mode === 'retry' ? $fields : array_replace($fields, ['idempotency_key' => (string) Str::uuid()]);
                DB::beginTransaction();
                if ($mode === 'revocation') {
                    Review::lock();
                    app(ManagePointStaff::class)->save($w['admin'], $actor->id, ['operation' => 'deactivate', 'expected_version' => $actor->membership()->first()->version, 'reason' => 'Synthetic concurrent revocation', 'idempotency_key' => (string) Str::uuid()]);
                    $winner = null;
                } elseif ($successor) {
                    $winner = $service->record($actor, $h, 'accepted', $fields);
                } else {
                    $winner = app(ReviewRestrictions::class)->record($w['admin'], $case, 'approve', $approval);
                }
                file_put_contents($file, json_encode(['schema' => $schema, 'action' => $action, 'actor' => $actor->id, 'case' => $case, 'batch' => $w['batch'], 'shipment' => $h, 'fields' => $child, 'winner' => $winner, 'retry' => $mode === 'retry', 'signal' => $file.'.ready'], JSON_THROW_ON_ERROR));
                $process = proc_open([PHP_BINARY, base_path('tests/Support/downstream-correction-race-worker.php'), $file], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
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
                    }usleep(10000);
                } while (microtime(true) < $deadline);
                $this->assertTrue($waiting, 'The competing '.$mode.' must wait on a real PostgreSQL lock.');
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
                $this->assertSame(0, DB::transactionLevel());
                if ($mode === 'dispatch') {
                    $this->assertSame(0, DB::table('downstream_records')->where('kind', 'dispatch')->count());
                    $this->assertEquals(5, app(\App\Support\Downstream\Context::class)->stock($w['output'])['reserved']);
                } elseif ($mode === 'complete') {
                    $this->assertSame(0, DB::table('downstream_records')->where('kind', 'complete')->count());
                } elseif ($mode === 'source_loss') {
                    $this->assertSame(0, DB::table('downstream_records')->where('kind', 'source_loss')->count());
                    $this->assertEquals(11, app(\App\Support\Downstream\Context::class)->stock($w['output'])['reservable']);
                }
                if ($mode === 'retry') {
                    $this->assertSame(1, DB::table('downstream_fact_revisions')->count());
                    // A separate still-open proposal proves root failure compensation, not savepoint rollback.
                    $other = $this->downstreamWork();
                    $loss = $this->downstreamRecord($other, 'source_loss');
                    $pending = $this->correction($other, $loss, 'loss_confirm', [$this->fact('downstream', $loss, 'loss_status', 'confirmed')], false, ['quantified_loss' => 'YES'], approve: false);
                    $other = array_replace($other, ['subject' => $other['batch'], 'investigator' => $other['receiver'], 'source' => app(Context::class)->source('downstream_correction', $loss, 'downstream_loss_confirm')]);
                    $this->rootFailure($other, $pending);
                }
            } finally {
                if (is_resource($process)) {
                    proc_terminate($process);
                    foreach ($pipes as $pipe) {
                        if (is_resource($pipe)) {
                            fclose($pipe);
                        }
                    }proc_close($process);
                }
                while (DB::connection('correction_race')->transactionLevel() > 0) {
                    DB::connection('correction_race')->rollBack();
                }
                DB::connection('correction_race')->statement('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
                DB::setDefaultConnection('pgsql');
                Schema::clearResolvedInstance('db.schema');
                DB::purge('correction_race');
                @unlink($file);
                @unlink($file.'.ready');
            }
            $this->assertFalse(DB::table('pg_namespace')->where('nspname', $schema)->exists());
        }
    }

    private function rootFailure(array $w, string $case): void
    {
        Storage::fake('directory_evidence');
        Storage::disk('directory_evidence')->put('retained.txt', 'Synthetic retained evidence');
        $before = [];
        foreach (DB::select('SELECT tablename FROM pg_tables WHERE schemaname=current_schema() ORDER BY tablename') as $t) {
            $before[$t->tablename] = DB::table($t->tablename)->get()->sortBy(fn ($r) => json_encode($r))->values()->toJson();
        }
        DB::unprepared("CREATE FUNCTION fail_correction_commit_fixture() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Synthetic deferred correction failure'; END; $$; CREATE CONSTRAINT TRIGGER fail_correction_commit_fixture AFTER INSERT ON downstream_review_effects DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fail_correction_commit_fixture();");
        $fields = $this->restrictionFields($w, 'approve', $case);
        $fields['evidence_kind'] = 'attachment';
        unset($fields['reference_issuer'],$fields['reference_code'],$fields['reference_attested']);
        try {
            app(ReviewRestrictions::class)->record($w['admin'], $case, 'approve', $fields, UploadedFile::fake()->image('private.png'));
            $this->fail('Root COMMIT must evaluate the deferred failure.');
        } catch (PDOException $e) {
            $this->assertStringContainsString('Synthetic deferred correction failure', $e->getMessage());
        }
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->get()->sortBy(fn ($r) => json_encode($r))->values()->toJson(), $table);
        }
        $this->assertSame(['retained.txt'], Storage::disk('directory_evidence')->allFiles());
    }
}
