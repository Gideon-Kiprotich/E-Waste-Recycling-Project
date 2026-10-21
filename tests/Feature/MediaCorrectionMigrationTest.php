<?php

namespace Tests\Feature;

use App\Actions\AuthorizeBatchMedia;
use App\Actions\CustodyHandovers;
use App\Actions\InspectItems;
use App\Actions\RecordVisit;
use App\Models\User;
use App\Support\Sanitization\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\BatchFixtures;
use Tests\Support\DownstreamFixtures;
use Tests\Support\RestrictionFixtures;
use Tests\TestCase;

class MediaCorrectionMigrationTest extends TestCase
{
    use BatchFixtures;
    use DownstreamFixtures;
    use RestrictionFixtures;

    public function test_fresh_and_populated_upgrade_preserves_original_records_without_inventing_cases(): void
    {
        foreach ([false, true] as $populated) {
            $schema = 'restriction_upgrade_'.str_replace('-', '', Str::uuid()->toString());
            $config = config('database.connections.pgsql');
            $this->assertSame('ewaste_testing', $config['database']);
            $config['search_path'] = $schema;
            config(['database.connections.restriction_upgrade' => $config]);
            DB::setDefaultConnection('restriction_upgrade');
            Schema::clearResolvedInstance('db.schema');
            try {
                DB::beginTransaction();
                DB::statement('CREATE SCHEMA "'.$schema.'"');
                $files = glob(database_path('migrations/*.php'));
                sort($files);
                foreach ($files as $file) {
                    if (basename($file) < '2026_10_20_000001_create_media_correction_review.php') {
                        (require $file)->up();
                    }
                }
                if ($populated) {
                    $w = $this->inspectionWork();
                    $fields = $this->inspectionFields($w['ids'][0]);
                    $fields['answers']['safety_concern'] = 'YES';
                    app(InspectItems::class)->inspect($w['receiver'], $w['ids'][0], $fields);
                    $this->handoverBoundary();
                    $batch = $this->batchWork();
                    foreach (['draft', 'commit', 'output', 'measurement', 'accounted'] as $kind) {
                        $this->batchRecord($batch, $kind);
                    }
                    $authority = app(AuthorizeBatchMedia::class);
                    $medium = $batch['media'][0];
                    $authority->record($batch['receiver'], $batch['batch'], $medium, $this->handoverEvidence() + ['expected_version' => 0, 'expected_context' => $authority->token($batch['receiver'], $batch['batch'], $medium), 'reason' => 'Synthetic retained batch authority before upgrade', 'checks' => array_fill_keys(['identity_confirmed', 'safe_media', 'method_scope_known', 'no_unresolved_restriction'], 'YES')]);
                    $this->handoverBoundary();
                    $this->mediaSatisfied($this->mediumWork($batch, $medium));
                    $returned = $this->mediaWork();
                    $this->mediaSatisfied($returned);
                    $this->reusePassed($returned);
                    $this->reuseRecord($returned, 'authorize');
                    $this->reuseRecord($returned, 'acknowledge', [], true);
                }
                if ($populated) {
                    $disputed = $this->inventoryWork();
                    $derived = $this->identifyMedium($disputed);
                    $entry = DB::table('media_inventory_entries')->where('medium_id', $derived)->firstOrFail();
                    $this->inventoryRecord($disputed, 'correct', ['entry_id' => $entry->id, 'entry_state' => 'disputed']);
                    $standalone = $this->inventoryWork('NO');
                    $drive = $this->standaloneAtParentFacility($standalone);
                    $this->inventoryRecord($standalone, 'link', ['existing_code' => DB::table('subjects')->where('id', $drive)->value('public_code')]);
                    $entry = DB::table('media_inventory_entries')->where('medium_id', $drive)->firstOrFail();
                    $this->inventoryRecord($standalone, 'correct', ['entry_id' => $entry->id, 'entry_state' => 'disputed']);
                    $reported = $this->mediaWork();
                    $this->mediaSatisfied($reported);
                    $this->reusePassed($reported);
                    $this->reuseRecord($reported, 'authorize');
                    $this->reuseRecord($reported, 'exception', [], true);
                    $closed = $this->downstreamWork();
                    $shipment = $this->shipmentDraft($closed);
                    $this->downstreamRecord($closed, 'reserve', $shipment);
                    $this->downstreamRecord($closed, 'dispatch', $shipment);
                    $this->downstreamRecord($closed, 'accept', $shipment, true);
                    $this->downstreamRecord($closed, 'complete', $shipment, true);
                    $this->downstreamRecord($closed, 'close');
                    $fraction = $this->downstreamWork();
                    $shipment = $this->shipmentDraft($fraction, ['kg' => '4.000']);
                    $this->downstreamRecord($fraction, 'reserve', $shipment, false, ['kg' => '4.000']);
                    $this->downstreamRecord($fraction, 'dispatch', $shipment, false, ['kg' => '4.000']);
                    $restriction = array_replace($w, ['subject' => $w['ids'][0], 'investigator' => $w['receiver'], 'source' => DB::table('restriction_sources')->where('source_kind', 'inspection')->where('subject_id', $w['ids'][0])->where('fact_key', 'safety_concern')->firstOrFail()]);
                    $case = $this->openRestriction($restriction);
                    $this->restrictionRecord($restriction, $case, 'assign');
                }
                if ($populated) {
                    $mixed = $this->batchWork(0);
                    $secondOwner = User::factory()->create();
                    $second = $this->device($secondOwner, 'laptop')->subject_id;
                    $request = $this->readyRequest($mixed['admin'], $secondOwner, [$second], 'drop_off');
                    app(RecordVisit::class)->record($mixed['pointActor'], $request, $this->visitFields($request));
                    $this->handoverBoundary();
                    $fields = $this->initiateFields($mixed['pointActor'], $mixed['receiver']);
                    $fields['subjects'] = [$second.':'.DB::table('subjects')->where('id', $second)->value('version')];
                    $transfer = app(CustodyHandovers::class)->initiate($mixed['pointActor'], $fields);
                    $this->handoverBoundary();
                    app(CustodyHandovers::class)->change($mixed['receiver'], $transfer, 'acknowledged', $this->acknowledgeFields($transfer));
                    $this->handoverBoundary();
                    $inspection = $this->inspectionFields($second, 'laptop-healthy');
                    $inspection['answers']['data_status'] = 'NO';
                    app(InspectItems::class)->inspect($mixed['receiver'], $second, $inspection);
                    app(InspectItems::class)->decide($mixed['receiver'], $second, $this->decisionFields($second, 'recycle'));
                    $this->handoverBoundary();
                    $this->inventoryRecord(array_replace($mixed, ['ids' => [$second]]), 'attest');
                    $mixed['ids'][] = $second;
                    $this->batchRecord($mixed, 'draft');
                    $this->batchRecord($mixed, 'commit');
                    $this->batchRecord($mixed, 'output', ['net_kg' => '10.000']);
                    $this->batchRecord($mixed, 'accounted');
                    $this->assertSame(2, DB::table('batch_members as member')->join('items as item', 'item.subject_id', '=', 'member.subject_id')->where('member.batch_id', $mixed['batch'])->distinct()->count('item.owner_user_id'));
                }
                $before = [];
                foreach (DB::select('SELECT tablename FROM pg_tables WHERE schemaname=? ORDER BY tablename', [$schema]) as $table) {
                    $columns = Schema::getColumnListing($table->tablename);
                    $before[$table->tablename] = ['columns' => $columns, 'rows' => DB::table($table->tablename)->select($columns)->get()->sortBy(fn ($r) => json_encode($r))->values()->toJson()];
                }
                foreach ($files as $file) {
                    if (basename($file) >= '2026_10_20_000001_create_media_correction_review.php') {
                        (require $file)->up();
                    }
                }
                foreach ($before as $table => $snapshot) {
                    $this->assertSame($snapshot['rows'], DB::table($table)->select($snapshot['columns'])->get()->sortBy(fn ($r) => json_encode($r))->values()->toJson(), $table);
                }
                foreach (['media_review_effects', 'media_review_commits', 'restriction_impact_reviews', 'batch_media_discoveries'] as $newTable) {
                    $this->assertDatabaseCount($newTable, 0);
                }
                if ($populated) {
                    $this->assertTrue((bool) DB::selectOne('SELECT restriction_inspection_blocked(?) AS blocked', [$w['ids'][0]])->blocked);
                    $this->assertSame('satisfied', app(Context::class)->state($medium));
                    $this->assertSame('released', DB::table('items')->where('subject_id', $returned['ids'][0])->value('processing_state'));
                }
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            } finally {
                while (DB::connection('restriction_upgrade')->transactionLevel() > 0) {
                    DB::connection('restriction_upgrade')->rollBack();
                }
                DB::setDefaultConnection('pgsql');
                Schema::clearResolvedInstance('db.schema');
                DB::purge('restriction_upgrade');
            }
            $this->assertFalse(DB::table('pg_namespace')->where('nspname', $schema)->exists());
        }
    }
}
