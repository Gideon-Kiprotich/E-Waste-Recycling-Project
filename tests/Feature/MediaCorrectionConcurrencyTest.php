<?php

namespace Tests\Feature;

use App\Actions\InspectItems;
use App\Actions\ReviewRestrictions;
use App\Support\Collection\AdminMutation;
use App\Support\Restrictions\MediaReview;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDOException;
use Tests\Support\DownstreamFixtures;
use Tests\Support\RestrictionFixtures;
use Tests\TestCase;

class MediaCorrectionConcurrencyTest extends TestCase
{
    use DownstreamFixtures;
    use RestrictionFixtures;

    public function test_real_root_competing_proposals_approvals_retries_and_revocation(): void
    {
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1]);
        foreach (['approve', 'retry', 'revoke', 'source_change', 'fulfill'] as $mode) {
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
                if ($mode === 'fulfill') {
                    $w = $this->impactRaceWork();
                } else {
                    $w = $this->inventoryWork();
                    $medium = $this->identifyMedium($w);
                    $entry = DB::table('media_inventory_entries')->where('medium_id', $medium)->firstOrFail();
                    $this->inventoryRecord($w, 'correct', ['entry_id' => $entry->id, 'entry_state' => 'disputed']);
                    $w['subject'] = $w['ids'][0];
                    $w['investigator'] = $w['receiver'];
                    $w['source'] = DB::table('restriction_sources')->where('source_kind', 'media_correction')->where('subject_id', $w['subject'])->where('fact_key', 'association_confirm')->firstOrFail();
                }
                $case = $this->openRestriction($w);
                $this->restrictionRecord($w, $case, 'assign');
                if (in_array($mode, ['approve', 'source_change', 'fulfill'], true)) {
                    $this->restrictionRecord($w, $case, 'proposal', $mode === 'fulfill' ? ['checks' => array_fill_keys(array_keys(MediaReview::IMPACT_CHECKS), 'YES')] : []);
                    if ($mode === 'fulfill') {
                        $this->restrictionRecord($w, $case, 'approve');
                    }
                }
                $kind = $mode === 'fulfill' ? 'fulfill' : (in_array($mode, ['approve', 'source_change'], true) ? 'approve' : 'proposal');
                $actor = in_array($kind, ['approve', 'fulfill'], true) ? $w['admin'] : $w['investigator'];
                $fields = $this->restrictionFields($w, $kind, $case, $actor);
                if ($mode === 'fulfill') {
                    $fields['proposal_id'] = DB::table('restriction_records')->where('case_id', $case)->where('kind', 'proposal')->value('id');
                }
                $childFields = $mode === 'retry' ? $fields : array_replace($fields, ['idempotency_key' => Str::uuid()->toString()]);
                DB::beginTransaction();
                if ($mode === 'source_change') {
                    $inspection = $this->inspectionFields($w['subject'], 'laptop-healthy');
                    $inspection['answers']['charging_risk'] = 'YES';
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
                $this->assertSame(in_array($mode, ['approve', 'fulfill'], true) ? 1 : 0, DB::table('restriction_supersessions')->count());
                $this->assertDatabaseCount('first_receipts', 1);
                if ($mode === 'fulfill') {
                    $this->assertSame(1, DB::table('restriction_impact_reviews')->where('stage', 'fulfilled')->count());
                    $this->assertSame('obligation_fulfilled', DB::table('restriction_cases')->where('id', $case)->value('state'));
                }
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

    public function test_supplements_paired_measurements_and_reservations_compete_at_real_root_commit(): void
    {
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1]);
        foreach (['supplement', 'paired', 'reservation'] as $mode) {
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
                $w = $this->downstreamWork();
                $shipment = $mode === 'reservation' ? $this->shipmentDraft($w) : null;
                $this->batchRecord($w, 'discrepancy');
                $w['subject'] = $w['batch'];
                $w['investigator'] = $w['receiver'];
                $w['source'] = DB::table('restriction_sources')->where('source_kind', 'media_correction')->where('subject_id', $w['batch'])->where('fact_key', 'discovery_supplement')->orderByDesc('source_time')->firstOrFail();
                $case = $this->openRestriction($w);
                $this->restrictionRecord($w, $case, 'assign');
                $unit = ['id' => (string) Str::uuid(), 'parent_id' => $w['ids'][0], 'media_kind' => 'ssd', 'mode' => $mode === 'paired' ? 'reclassified' : 'unaccounted'];
                if ($mode === 'paired') {
                    $unit += ['source_output_id' => $w['output'], 'measurement' => ['mode' => 'net', 'net' => '0.500', 'source' => 'Synthetic separate weighing'], 'remaining' => ['mode' => 'net', 'net' => '9.500', 'source' => 'Synthetic remaining aggregate weighing']];
                }
                $proposal = $this->restrictionFields($w, 'proposal', $case);
                $proposal['checks'] = array_fill_keys(array_keys(MediaReview::DISCOVERY_CHECKS), 'YES');
                $proposal['details'] = ['count' => 1, 'units' => [$unit]];
                app(ReviewRestrictions::class)->record($w['receiver'], $case, 'proposal', $proposal);
                $this->handoverBoundary();
                $kind = 'approve';
                $actor = $w['admin'];
                $fields = $this->restrictionFields($w, $kind, $case);
                $childFields = $mode === 'reservation' ? $this->downstreamFields($w, 'reserve', $shipment) : array_replace($fields, ['idempotency_key' => (string) Str::uuid()]);
                DB::beginTransaction();
                $winner = app(ReviewRestrictions::class)->record($actor, $case, $kind, $fields);
                file_put_contents($file, json_encode(['schema' => $schema, 'action' => $mode === 'reservation' ? 'reserve' : 'review', 'batch' => $w['batch'], 'actor' => $mode === 'reservation' ? $w['receiver']->id : $actor->id, 'case' => $case, 'kind' => $kind, 'fields' => $childFields, 'retry' => $mode === 'retry', 'winner' => $winner, 'signal' => $file.'.ready'], JSON_THROW_ON_ERROR));
                $process = proc_open([PHP_BINARY, base_path('tests/Support/media-correction-race-worker.php'), $file], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
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
                $this->assertDatabaseCount('batch_media_discoveries', 1);
                $this->assertDatabaseCount('batch_outputs', 2);
                $this->assertDatabaseCount('batch_detachments', 0);
                $this->assertSame(0, DB::table('downstream_records')->where('kind', 'reserve')->count());
                $this->assertDatabaseCount('first_receipts', 1);
                if ($mode === 'supplement') {
                    $this->batchRecord($w, 'discrepancy');
                    $w['source'] = DB::table('restriction_sources')->where('source_kind', 'media_correction')->where('subject_id', $w['batch'])->where('fact_key', 'discovery_supplement')->where('currently_blocking', true)->firstOrFail();
                    $next = $this->openRestriction($w);
                    $this->restrictionRecord($w, $next, 'assign');
                    $proposal = $this->restrictionFields($w, 'proposal', $next);
                    $proposal['checks'] = array_fill_keys(array_keys(MediaReview::DISCOVERY_CHECKS), 'YES');
                    $unit['id'] = (string) Str::uuid();
                    $proposal['details'] = ['count' => 1, 'units' => [$unit]];
                    app(ReviewRestrictions::class)->record($w['receiver'], $next, 'proposal', $proposal);
                    $this->rootFailureCompensatesEvidence($w, $next);
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

    private function impactRaceWork(): array
    {
        $w = $this->batchWork(0);
        $this->batchRecord($w);
        $this->batchRecord($w, 'commit');
        $this->batchRecord($w, 'discrepancy');
        $w['subject'] = $w['batch'];
        $w['investigator'] = $w['receiver'];
        $w['source'] = DB::table('restriction_sources')->where('source_kind', 'media_correction')->where('subject_id', $w['batch'])->where('fact_key', 'discovery_error')->firstOrFail();
        $case = $this->openRestriction($w);
        $this->restrictionRecord($w, $case, 'assign');
        $this->restrictionRecord($w, $case, 'proposal', ['details' => ['accounted_media' => []]]);
        $this->restrictionRecord($w, $case, 'approve');
        $w['source'] = DB::table('restriction_sources')->where('source_kind', 'media_correction')->where('subject_id', $w['batch'])->where('fact_key', 'impact_continuity')->firstOrFail();

        return $w;
    }

    private function rootFailureCompensatesEvidence(array $w, string $case): void
    {
        $this->assertSame(0, DB::transactionLevel());
        Storage::fake('directory_evidence');
        Storage::disk('directory_evidence')->put('retained.txt', 'Synthetic retained file');
        $before = [];
        foreach (['restriction_cases', 'restriction_records', 'restriction_supersessions', 'restriction_epochs', 'restriction_epoch_steps', 'restriction_impacts', 'attachments', 'commands', 'domain_events', 'notification_outbox', 'custody_current', 'first_receipts', 'subjects', 'items', 'storage_assets', 'media_origins', 'batch_media_discoveries', 'batch_outputs', 'batch_records', 'dismantling_batches', 'media_review_effects', 'media_review_commits', 'restriction_impact_reviews'] as $table) {
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
