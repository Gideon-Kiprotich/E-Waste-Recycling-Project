<?php

namespace Tests\Feature;

use App\Actions\AcceptDownstreamResponsibility;
use App\Support\Sanitization\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\DownstreamCorrectionFixtures;
use Tests\Support\PriorMediaCorrectionFixtures;
use Tests\TestCase;

class DownstreamCorrectionMigrationTest extends TestCase
{
    use DownstreamCorrectionFixtures, PriorMediaCorrectionFixtures;

    public function test_fresh_and_populated_native_upgrade_retains_every_original_column_and_history(): void
    {
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1]);
        foreach (['fresh', 'movements', 'detached', 'new_discovery', 'recognized_discovery', 'phase16_reviews'] as $scenario) {
            $schema = 'downstream_correction_upgrade_'.str_replace('-', '', (string) Str::uuid());
            $config = config('database.connections.pgsql');
            $this->assertSame('ewaste_testing', $config['database']);
            $config['search_path'] = $schema;
            config(['database.connections.correction_upgrade' => $config]);
            DB::setDefaultConnection('correction_upgrade');
            Schema::clearResolvedInstance('db.schema');
            try {
                DB::statement('CREATE SCHEMA "'.$schema.'"');
                foreach (glob(database_path('migrations/*.php')) as $migration) {
                    if (basename($migration) < '2026_10_22_000001_create_downstream_corrections.php'
                        || ($scenario === 'phase16_reviews' && basename($migration) < '2026_10_22_000005_seal_downstream_boundaries.php')) {
                        DB::transaction(fn () => (require $migration)->up());
                    }
                }
                $states = [];
                if ($scenario === 'movements') {
                    $partial = $this->downstreamWork();
                    $h = $this->shipmentDraft($partial);
                    $this->downstreamRecord($partial, 'reserve', $h);
                    $this->downstreamRecord($partial, 'dispatch', $h, overrides: ['kg' => '4.001']);
                    $this->downstreamRecord($partial, 'accept', $h, true, ['kg' => '1.001']);
                    $this->downstreamRecord($partial, 'complete', $h, true, ['kg' => '1.001']);
                    $closed = $this->downstreamWork();
                    $ch = $this->shipmentDraft($closed);
                    foreach (['reserve', 'dispatch', 'accept', 'complete'] as $kind) {
                        $this->downstreamRecord($closed, $kind, $ch, in_array($kind, ['accept', 'complete'], true));
                    }
                    $this->downstreamRecord($closed, 'close');
                    $this->downstreamRecord($closed, 'measurement_dispute');
                    $loss = $this->downstreamWork();
                    $this->downstreamRecord($loss, 'source_loss');
                }
                if ($scenario === 'detached') {
                    $drive = $this->downstreamWork(true);
                    $dh = $this->shipmentDraft($drive);
                    $this->downstreamRecord($drive, 'reserve', $dh);
                    $this->downstreamRecord($drive, 'dispatch', $dh);
                    $this->downstreamRecord($drive, 'accept', $dh, true);
                    $this->downstreamAuthorize($drive, $drive['recipient']);
                    $this->mediaRecord(array_replace($this->mediumWork($drive, $drive['media'][0]), ['receiver' => $drive['recipient']]), 'revalidation');
                    $states[$drive['media'][0]] = app(Context::class)->state($drive['media'][0]);
                    $destroyed = $this->mediaWork('hdd');
                    $attempt = $this->mediaFields($destroyed);
                    $attempt['technique'] = 'media_shredding';
                    unset($attempt['tool_version']);
                    $this->mediaSatisfied($destroyed, $attempt);
                    $states[$destroyed['ids'][0]] = app(Context::class)->state($destroyed['ids'][0]);
                }
                if (in_array($scenario, ['new_discovery', 'recognized_discovery'], true)) {
                    foreach ([$scenario === 'recognized_discovery'] as $recognized) {
                        $w = $this->batchWork(0);
                        $existing = $recognized ? $this->standaloneAtParentFacility($w) : null;
                        $this->batchRecord($w);
                        $this->batchRecord($w, 'commit');
                        $this->batchRecord($w, 'output', ['net_kg' => '9.500']);
                        $this->batchRecord($w, 'discrepancy');
                        $discovery = $this->typedWork($w, 'discovery_supplement');
                        $medium = $existing ?? (string) Str::uuid();
                        $unit = ['id' => $recognized ? (string) Str::uuid() : $medium, 'parent_id' => $w['ids'][0], 'media_kind' => 'ssd', 'mode' => 'unaccounted', 'measurement' => ['mode' => 'net', 'net' => '0.500', 'source' => 'Synthetic retained separate weighing']];
                        if ($recognized) {
                            $unit['existing_code'] = DB::table('subjects')->where('id', $medium)->value('public_code');
                        }
                        $this->approveTyped($discovery, ['count' => 1, 'units' => [$unit]]);
                        $this->fulfillContinuities($this->continuities($discovery));
                        $w['media'] = [$medium];
                        $this->downstreamAuthorize($w, $w['receiver']);
                        $this->mediaSatisfied($this->mediumWork($w, $medium));
                        $this->batchRecord($w, 'accounted');
                        $states[$medium] = app(Context::class)->state($medium);
                    }
                }
                if ($scenario === 'phase16_reviews') {
                    $reviewed = $this->downstreamWork();
                    $rh = $this->shipmentDraft($reviewed);
                    $this->downstreamRecord($reviewed, 'reserve', $rh);
                    $this->downstreamRecord($reviewed, 'dispatch', $rh);
                    $shortage = $this->downstreamRecord($reviewed, 'shortage', $rh, true);
                    $this->correction($reviewed, $shortage, 'report_error', [$this->fact('downstream', $shortage, 'active', '0')], facts: ['original_error' => 'YES']);
                    $continuities = $this->downstreamContinuities($reviewed, false);
                    $this->downstreamRecord($reviewed, 'accept', $rh, true);
                    $this->fulfillDownstream($reviewed, $continuities);
                    $this->downstreamRecord($reviewed, 'complete', $rh, true);
                    $this->downstreamRecord($reviewed, 'close');

                    $succession = $this->downstreamWork();
                    $successor = $this->pointStaff($succession['admin'], $succession['destination']);
                    $this->handoverBoundary();
                    $sh = $this->shipmentDraft($succession);
                    $this->downstreamRecord($succession, 'reserve', $sh);
                    $this->downstreamRecord($succession, 'dispatch', $sh);
                    $draft = DB::table('downstream_shipments')->where('id', $sh)->value('created_record_id');
                    $this->correction($succession, $draft, 'recipient', [], false, extra: ['receiver_actor_id' => $successor->id, 'profile_record_id' => $succession['profile_record']]);
                    $responsibility = app(AcceptDownstreamResponsibility::class);
                    $responsibility->record($successor, $sh, 'accepted', $this->handoverEvidence() + ['attested' => '1', 'expected_version' => 1, 'expected_context' => $responsibility->token($successor, $sh), 'reason' => 'Synthetic retained responsibility before the boundary upgrade']);
                    $this->handoverBoundary();
                }
                $this->assertSame(0, DB::transactionLevel());
                $before = [];
                foreach (DB::select('SELECT tablename FROM pg_tables WHERE schemaname=? ORDER BY tablename', [$schema]) as $t) {
                    $columns = Schema::getColumnListing($t->tablename);
                    $before[$t->tablename] = ['columns' => $columns, 'rows' => DB::table($t->tablename)->select($columns)->get()->sortBy(fn ($r) => json_encode($r))->values()->toJson()];
                }
                $effectiveBefore = [];
                if ($scenario === 'phase16_reviews') {
                    foreach (['downstream_effective_records', 'downstream_effective_shipments', 'downstream_effective_measurements'] as $view) {
                        $effectiveBefore[$view] = DB::table($view)->orderBy('id')->get()->toJson();
                    }
                }
                DB::transaction(function () use ($scenario) {
                    foreach (glob(database_path('migrations/2026_10_22_*.php')) as $m) {
                        if ($scenario !== 'phase16_reviews' || basename($m) === '2026_10_22_000005_seal_downstream_boundaries.php') {
                            (require $m)->up();
                        }
                    }
                });
                foreach ($before as $table => $snapshot) {
                    $this->assertSame($snapshot['rows'], DB::table($table)->select($snapshot['columns'])->get()->sortBy(fn ($r) => json_encode($r))->values()->toJson(), $table);
                }
                foreach ($effectiveBefore as $view => $rows) {
                    $this->assertSame($rows, DB::table($view)->orderBy('id')->get()->toJson(), $view);
                }
                foreach (['downstream_review_effects', 'downstream_fact_revisions', 'downstream_recipient_records'] as $table) {
                    if ($scenario === 'phase16_reviews') {
                        $this->assertGreaterThan(0, DB::table($table)->count());
                    } else {
                        $this->assertDatabaseCount($table, 0);
                    }
                }
                foreach (['downstream_records' => 'downstream_effective_records', 'downstream_shipments' => 'downstream_effective_shipments', 'batch_records' => 'downstream_effective_measurements'] as $table => $view) {
                    if ($scenario !== 'phase16_reviews') {
                        $this->assertEquals(DB::table($table)->orderBy('id')->get(), DB::table($view)->orderBy('id')->get());
                    }
                }
                foreach ($states as $medium => $state) {
                    $this->assertSame($state, app(Context::class)->state($medium));
                }
                if ($scenario === 'movements') {
                    $this->assertSame('closed_later_evidence_exception', DB::selectOne('SELECT downstream_status(?) AS state', [$closed['batch']])->state);
                    $this->assertSame(1, DB::table('downstream_records')->where('kind', 'close')->count());
                    $this->assertEquals(5.999, app(\App\Support\Downstream\Context::class)->stock($partial['output'])['accounted']);
                }
                if (in_array($scenario, ['new_discovery', 'recognized_discovery'], true)) {
                    $this->assertSame(1, DB::table('batch_media_discoveries')->count());
                    $this->assertGreaterThan(0, DB::table('restriction_impact_reviews')->where('stage', 'fulfilled')->count());
                }
                if ($scenario === 'detached') {
                    $this->assertTrue(DB::table('sanitization_current')->where('subject_id', $destroyed['ids'][0])->value('destruction_reported'));
                }
                if ($scenario === 'phase16_reviews') {
                    $this->assertEquals(0, DB::selectOne('SELECT downstream_remaining(?) AS kg', [DB::table('downstream_records')->where('shipment_id', $rh)->where('kind', 'dispatch')->value('id')])->kg);
                    $this->assertSame('closed_recorded_outcomes', DB::selectOne('SELECT downstream_status(?) AS state', [$reviewed['batch']])->state);
                    foreach ($continuities as [$case, $impact]) {
                        $this->assertTrue(DB::selectOne('SELECT downstream_impact_fulfilled(?) AS fulfilled', [$impact])->fulfilled);
                    }
                    $this->assertSame($successor->id, DB::table('downstream_effective_shipments')->where('id', $sh)->value('receiver_actor_id'));
                    $this->assertTrue(DB::selectOne('SELECT downstream_recipient_authority(?) AS current', [$sh])->current);
                    $this->assertSame(0, DB::table('downstream_records')->where('shipment_id', $sh)->where('kind', 'accept')->count());
                }
            } finally {
                while (DB::connection('correction_upgrade')->transactionLevel() > 0) {
                    DB::connection('correction_upgrade')->rollBack();
                }
                DB::connection('correction_upgrade')->statement('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
                DB::setDefaultConnection('pgsql');
                Schema::clearResolvedInstance('db.schema');
                DB::purge('correction_upgrade');
            }
            $this->assertFalse(DB::table('pg_namespace')->where('nspname', $schema)->exists());
        }
    }
}
