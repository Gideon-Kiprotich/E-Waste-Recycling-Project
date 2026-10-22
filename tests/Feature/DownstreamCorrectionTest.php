<?php

namespace Tests\Feature;

use App\Actions\AcceptDownstreamResponsibility;
use App\Actions\ManageOutputProfiles;
use App\Support\Downstream\Context;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PriorMediaCorrectionFixtures;
use Tests\TestCase;

class DownstreamCorrectionTest extends TestCase
{
    use DatabaseTransactions, PriorMediaCorrectionFixtures, \Tests\Support\DownstreamCorrectionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (glob(database_path('migrations/2026_10_22_*.php')) as $file) {
            if (! DB::table('migrations')->where('migration', basename($file, '.php'))->exists()) {
                (require $file)->up();
            }
        }
    }

    public function test_initial_effective_accounting_preserves_partial_movement_facts(): void
    {
        $w = $this->downstreamWork();
        $h = $this->shipmentDraft($w);
        $this->downstreamRecord($w, 'reserve', $h);
        $this->downstreamRecord($w, 'dispatch', $h, overrides: ['kg' => '4.001']);
        $this->downstreamRecord($w, 'accept', $h, true, ['kg' => '1.001']);
        $this->downstreamRecord($w, 'complete', $h, true, ['kg' => '1.001']);
        $stock = app(Context::class)->stock($w['output']);
        $this->assertEquals('5.999', $stock['accounted']);
        $this->assertEquals('1.001', $stock['completed']);
        $this->assertSame(0, DB::table('downstream_fact_revisions')->count());
        $this->assertEquals(DB::table('downstream_records')->orderBy('id')->get(), DB::table('downstream_effective_records')->orderBy('id')->get());
    }

    public function test_reporting_error_releases_only_the_disputed_slice_without_restock_or_receipt(): void
    {
        $w = $this->downstreamWork();
        $h = $this->shipmentDraft($w);
        $this->downstreamRecord($w, 'reserve', $h);
        $dispatch = $this->downstreamRecord($w, 'dispatch', $h);
        $shortage = $this->downstreamRecord($w, 'shortage', $h, true, ['kg' => '2.001']);
        $original = DB::table('downstream_records')->where('id', $shortage)->first();
        $case = $this->correction($w, $shortage, 'report_error', [$this->fact('downstream', $shortage, 'active', '0')], facts: ['original_error' => 'YES']);
        $this->assertEquals($original, DB::table('downstream_records')->where('id', $shortage)->first());
        $this->assertEquals(10, DB::selectOne('SELECT downstream_remaining(?) AS remaining', [$dispatch])->remaining);
        $this->assertEquals(0, app(Context::class)->stock($w['output'])['accounted']);
        $this->assertSame(0, DB::table('downstream_records')->where('kind', 'accept')->count());
        $this->assertSame('superseded', DB::table('restriction_cases')->where('id', $case)->value('state'));
        $this->assertGreaterThan(0, DB::table('restriction_impacts')->count());
    }

    public function test_located_material_retains_truth_then_requires_real_receipt(): void
    {
        $w = $this->downstreamWork();
        $h = $this->shipmentDraft($w);
        $this->downstreamRecord($w, 'reserve', $h);
        $dispatch = $this->downstreamRecord($w, 'dispatch', $h);
        $missing = $this->downstreamRecord($w, 'shortage', $h, true);
        $this->correction($w, $missing, 'located', [$this->fact('downstream', $missing, 'active', '0')], facts: ['located_later' => 'YES']);
        $cases = $this->downstreamContinuities($w, false);
        $this->assertEquals(10, DB::selectOne('SELECT downstream_remaining(?) AS remaining', [$dispatch])->remaining);
        $this->downstreamRecord($w, 'accept', $h, true);
        $this->assertSame('shortage', DB::table('downstream_records')->where('id', $missing)->value('kind'));
        $this->fulfillDownstream($w, $cases);
        $this->downstreamRecord($w, 'complete', $h, true);
        $this->assertEquals(0, app(Context::class)->stock($w['output'])['accounted']);
        $this->assertTrue(DB::selectOne('SELECT downstream_closable(?) AS ok', [$w['batch']])->ok);
    }

    public function test_damaged_aggregate_needs_reviewed_disposition_then_separate_completion(): void
    {
        $w = $this->downstreamWork();
        $h = $this->shipmentDraft($w);
        $this->downstreamRecord($w, 'reserve', $h);
        $this->downstreamRecord($w, 'dispatch', $h);
        $accept = $this->downstreamRecord($w, 'accept', $h, true, ['condition' => 'damaged']);
        $this->correction($w, $accept, 'damage_disposition', [$this->fact('downstream', $accept, 'disposition', 'disposed')], facts: ['known_safe' => 'YES']);
        $this->assertSame(0, DB::table('downstream_records')->where('kind', 'complete')->count());
        $this->downstreamContinuities($w);
        $this->downstreamRecord($w, 'complete', $h, true, ['outcome' => 'disposed']);
        $this->assertSame('damaged', DB::table('downstream_records')->where('id', $accept)->value('condition'));
        $this->assertEquals(10, app(Context::class)->stock($w['output'])['completed']);
    }

    public function test_quantified_source_loss_closes_with_loss_without_recovered_mass(): void
    {
        $w = $this->downstreamWork();
        $loss = $this->downstreamRecord($w, 'source_loss');
        $this->correction($w, $loss, 'loss_confirm', [$this->fact('downstream', $loss, 'loss_status', 'confirmed')], false, ['quantified_loss' => 'YES']);
        $this->assertTrue(DB::selectOne('SELECT downstream_closable(?) AS ok', [$w['batch']])->ok);
        $this->downstreamRecord($w, 'close');
        $this->assertSame('closed_recorded_outcomes_with_loss', DB::selectOne('SELECT downstream_status(?) AS status', [$w['batch']])->status);
        $stock = app(Context::class)->stock($w['output']);
        $this->assertEquals(10, $stock['source_loss']);
        $this->assertEquals(0, $stock['completed']);
        $this->assertEquals(0, $stock['reservable']);
    }

    public function test_false_source_loss_requires_continuous_present_stock_before_releasing_availability(): void
    {
        $w = $this->downstreamWork();
        $loss = $this->downstreamRecord($w, 'source_loss', overrides: ['kg' => '2.000']);
        $this->correction($w, $loss, 'loss_error', [$this->fact('downstream', $loss, 'active', '0')], false, ['original_error' => 'YES', 'continuous_control' => 'YES', 'present_unallocated' => 'YES']);
        $this->assertEquals(10, app(Context::class)->stock($w['output'])['reservable']);
        $this->assertSame('2.000', DB::table('downstream_records')->where('id', $loss)->value('kg'));
    }

    public function test_same_destination_successor_accepts_responsibility_before_actual_receipt(): void
    {
        $w = $this->downstreamWork();
        $successor = $this->pointStaff($w['admin'], $w['destination']);
        $this->handoverBoundary();
        $h = $this->shipmentDraft($w);
        $this->downstreamRecord($w, 'reserve', $h);
        $this->downstreamRecord($w, 'dispatch', $h);
        $draft = DB::table('downstream_shipments')->where('id', $h)->value('created_record_id');
        $this->correction($w, $draft, 'recipient', [], false, extra: ['receiver_actor_id' => $successor->id, 'profile_record_id' => $w['profile_record']]);
        $this->assertNull(DB::table('downstream_effective_shipments')->where('id', $h)->value('receiver_actor_id'));
        $this->receiptSignIn($successor)->get(route('downstream.index'))->assertOk()->assertSee($h);
        $this->receiptSignIn($successor)->get(route('downstream.shipment', $h))->assertOk()->assertSee('Accept recipient responsibility');
        $a = app(AcceptDownstreamResponsibility::class);
        $f = $this->handoverEvidence() + ['attested' => '1', 'expected_version' => 1, 'expected_context' => $a->token($successor->fresh(), $h), 'reason' => 'Synthetic current responsibility acceptance only'];
        $id = $a->record($successor->fresh(), $h, 'accepted', $f);
        $this->handoverBoundary();
        $this->assertSame($id, $a->record($successor->fresh(), $h, 'accepted', $f));
        $this->assertSame(0, DB::table('downstream_records')->where('kind', 'accept')->count());
        $this->receiptSignIn($w['recipient'])->get(route('downstream.shipment', $h))->assertNotFound();
        $w['recipient'] = $successor;
        $cases = $this->downstreamContinuities($w, false);
        $this->downstreamRecord($w, 'accept', $h, true);
        $this->fulfillDownstream($w, $cases);
        $this->downstreamRecord($w, 'complete', $h, true);
        $this->assertSame($successor->id, DB::table('downstream_effective_shipments')->where('id', $h)->value('receiver_actor_id'));
        $this->assertSame(1, DB::table('downstream_records')->where('kind', 'accept')->count());
    }

    public function test_complete_measurement_vector_restates_existing_allocations_without_production_or_second_deduction(): void
    {
        $w = $this->downstreamWork();
        $h = $this->shipmentDraft($w);
        $reserve = $this->downstreamRecord($w, 'reserve', $h);
        $dispatch = $this->downstreamRecord($w, 'dispatch', $h);
        $accept = $this->downstreamRecord($w, 'accept', $h, true);
        $measurement = DB::table('batch_outputs')->where('subject_id', $w['output'])->value('measurement_record_id');
        $input = DB::table('dismantling_batches')->where('subject_id', $w['batch'])->value('input_measurement_id');
        $members = DB::table('batch_members')->get();
        $this->correction($w, $measurement, 'measurement', [
            $this->fact('batch', $measurement, 'net_kg', '9.900'), $this->fact('batch', $input, 'net_kg', '9.900'),
            $this->fact('downstream', $reserve, 'kg', '9.900'), $this->fact('downstream', $dispatch, 'kg', '9.900'), $this->fact('downstream', $accept, 'kg', '9.900'),
        ], false, ['original_error' => 'YES', 'same_allocation' => 'YES'], both: true);
        $this->assertSame('10.000', DB::table('downstream_records')->where('id', $dispatch)->value('kg'));
        $this->assertEquals('9.900', app(Context::class)->stock($w['output'])['accepted']);
        $this->assertEquals(0, app(Context::class)->stock($w['output'])['accounted']);
        $this->assertEquals($members, DB::table('batch_members')->get());
        $cases = $this->downstreamContinuities($w, false);
        $this->batchRecord($w, 'accounted');
        $this->fulfillDownstream($w, $cases);
        $this->downstreamRecord($w, 'complete', $h, true, ['kg' => '9.900']);
        $this->assertSame(1, DB::table('batch_outputs')->count());
        $this->receiptSignIn($w['receiver'])->get(route('downstream.show', $w['batch']))->assertOk()->assertSee('Original measured production: 10.000 kg')->assertSee('Effective measured production: 9.900 kg')->assertSee('Original measured batch input: 10.000 kg');
        $this->assertTrue(DB::selectOne('SELECT downstream_closable(?) AS ok', [$w['batch']])->ok);
    }

    public function test_whole_drive_reporting_error_keeps_custody_until_actual_acknowledgment(): void
    {
        $w = $this->downstreamWork(true);
        $h = $this->shipmentDraft($w);
        $this->downstreamRecord($w, 'reserve', $h);
        $this->downstreamRecord($w, 'dispatch', $h);
        $custody = DB::table('custody_current')->where('subject_id', $w['output'])->first();
        $identity = DB::table('items')->where('subject_id', $w['output'])->first();
        $firstReceipts = DB::table('first_receipts')->count();
        $shortage = $this->downstreamRecord($w, 'shortage', $h, true);
        $this->correction($w, $shortage, 'report_error', [$this->fact('downstream', $shortage, 'active', '0')], facts: ['original_error' => 'YES']);
        $this->assertEquals($custody, DB::table('custody_current')->where('subject_id', $w['output'])->first());
        $this->assertSame(0, DB::table('downstream_records')->where('kind', 'accept')->count());
        $this->assertEquals(0, app(Context::class)->stock($w['output'])['reservable']);
        $cases = $this->downstreamContinuities($w, false);
        $this->downstreamRecord($w, 'accept', $h, true);
        $this->downstreamAuthorize($w, $w['recipient']);
        $this->mediaRecord(array_replace($this->mediumWork($w, $w['output']), ['receiver' => $w['recipient']]), 'revalidation');
        $this->fulfillDownstream($w, $cases);
        $this->downstreamRecord($w, 'complete', $h, true);
        $this->assertEquals($identity, DB::table('items')->where('subject_id', $w['output'])->first());
        $this->assertSame($firstReceipts, DB::table('first_receipts')->count());
        $this->assertSame(DB::table('output_profile_records')->where('id', $w['profile_record'])->value('facility_party_id'), DB::table('custody_current')->where('subject_id', $w['output'])->value('party_id'));
        $this->assertSame(1, DB::table('downstream_records')->where('kind', 'dispatch')->count());
        $this->assertSame(1, DB::table('downstream_records')->where('kind', 'accept')->count());
    }

    public function test_confirmed_transit_loss_closes_with_only_received_material_recovered(): void
    {
        $w = $this->downstreamWork();
        $h = $this->shipmentDraft($w);
        $this->downstreamRecord($w, 'reserve', $h);
        $dispatch = $this->downstreamRecord($w, 'dispatch', $h);
        $this->downstreamRecord($w, 'accept', $h, true, ['kg' => '6.999']);
        $loss = $this->downstreamRecord($w, 'downstream_loss', $h, true, ['kg' => '3.001']);
        $this->correction($w, $loss, 'loss_confirm', [$this->fact('downstream', $loss, 'loss_status', 'confirmed')], facts: ['quantified_loss' => 'YES']);
        $this->downstreamContinuities($w);
        $this->downstreamRecord($w, 'complete', $h, true, ['kg' => '6.999']);
        $this->downstreamRecord($w, 'close');
        $stock = app(Context::class)->stock($w['output']);
        $this->assertEquals(0, $stock['accounted']);
        $this->assertEquals(0, $stock['source_loss']);
        $this->assertEquals('3.001', $stock['downstream_loss']);
        $this->assertEquals('6.999', $stock['completed']);
        $this->assertEquals(0, DB::selectOne('SELECT downstream_remaining(?) AS kg', [$dispatch])->kg);
        $this->assertSame('closed_recorded_outcomes_with_loss', DB::selectOne('SELECT downstream_status(?) AS state', [$w['batch']])->state);
    }

    public static function discoveryOrigins(): array
    {
        return [['ssd', false], ['hdd', false], ['ssd', true], ['hdd', true]];
    }

    #[DataProvider('discoveryOrigins')]
    public function test_discovery_drive_moves_once_under_real_provenance_and_recipient_context_six(string $kind, bool $recognized): void
    {
        $w = $this->batchWork(0);
        $w['destination'] = $this->facility($w['point']);
        $w['recipient'] = $this->pointStaff($w['admin'], $w['destination']);
        $this->handoverBoundary();
        $existing = $recognized ? $this->standaloneAtParentFacility($w, $kind) : null;
        $existingOrigin = $existing ? DB::table('media_origins')->where('subject_id', $existing)->first() : null;
        $this->batchRecord($w);
        $this->batchRecord($w, 'commit');
        $this->batchRecord($w, 'output', ['net_kg' => '9.500']);
        $this->batchRecord($w, 'discrepancy');
        $discovery = $this->typedWork($w, 'discovery_supplement');
        $medium = $existing ?? (string) Str::uuid();
        $unit = ['id' => (string) Str::uuid(), 'parent_id' => $w['ids'][0], 'media_kind' => $kind, 'mode' => 'unaccounted', 'measurement' => ['mode' => 'net', 'net' => '0.500', 'source' => 'Synthetic recorded separate output measurement']];
        if ($recognized) {
            $unit['existing_code'] = DB::table('subjects')->where('id', $existing)->value('public_code');
        } else {
            $unit['id'] = $medium;
        }
        $this->approveTyped($discovery, ['count' => 1, 'units' => [$unit]]);
        $this->fulfillContinuities($this->continuities($discovery));
        $w['media'] = [$medium];
        $this->downstreamAuthorize($w, $w['receiver']);
        $this->mediaSatisfied($this->mediumWork($w, $medium));
        $this->batchRecord($w, 'accounted');
        $w['output'] = $medium;
        $w['profile'] = (string) Str::uuid();
        $w['profile_record'] = app(ManageOutputProfiles::class)->record($w['admin'], $w['profile'], array_replace($this->profileFields($w), ['material_key' => 'canonical_'.$kind]));
        $this->handoverBoundary();
        $production = DB::table('batch_outputs')->where('subject_id', $medium)->value('production_record_id');
        $identity = DB::table('items')->where('subject_id', $medium)->first();
        $origin = DB::table('media_origins')->where('subject_id', $medium)->first();
        $receipts = DB::table('first_receipts')->count();
        $this->correction($w, $production, 'discovery_movement', [$this->fact('discovery', $medium, 'movement', 'approved')], false);
        $this->downstreamContinuities($w);
        $this->downstreamAuthorize($w, $w['receiver']);
        $this->mediaRecord($this->mediumWork($w, $medium), 'revalidation');
        $h = $this->shipmentDraft($w);
        $this->downstreamRecord($w, 'reserve', $h);
        $this->downstreamRecord($w, 'dispatch', $h);
        $this->assertSame('review_required', app(\App\Support\Sanitization\Context::class)->state($medium));
        $this->downstreamRecord($w, 'accept', $h, true);
        $recipientCases = $this->downstreamContinuities($w, false);
        $this->downstreamAuthorize($w, $w['recipient']);
        $received = array_replace($this->mediumWork($w, $medium), ['receiver' => $w['recipient']]);
        $this->mediaRecord($received, 'revalidation');
        $this->fulfillDownstream($w, $recipientCases);
        $this->assertSame(6, (int) DB::table('media_authorizations')->orderByDesc('version')->value('authorization_context_version'));
        $this->downstreamRecord($w, 'complete', $h, true);
        $this->assertEquals($identity, DB::table('items')->where('subject_id', $medium)->first());
        $this->assertEquals($origin, DB::table('media_origins')->where('subject_id', $medium)->first());
        if ($recognized) {
            $this->assertEquals($existingOrigin, $origin);
        }
        $this->assertSame($receipts, DB::table('first_receipts')->count());
        $this->assertSame(0, DB::table('batch_detachments')->count());
        $this->assertSame(1, DB::table('downstream_records')->where('output_id', $medium)->where('kind', 'dispatch')->count());
        $this->mediaRecord($received, 'attempt', $this->mediaFields($received, 'attempt', 'failed'));
        $this->assertNotSame('satisfied', app(\App\Support\Sanitization\Context::class)->state($medium));
        $this->assertSame(1, DB::table('downstream_records')->where('output_id', $medium)->where('kind', 'complete')->count());
    }
}
