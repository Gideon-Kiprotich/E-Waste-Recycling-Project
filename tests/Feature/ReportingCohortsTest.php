<?php

namespace Tests\Feature;

use App\Actions\CollectionRequests;
use App\Actions\CustodyHandovers;
use App\Actions\InspectItems;
use App\Actions\RecordVisit;
use App\Actions\RefurbishItems;
use App\Models\User;
use App\Support\Handovers\Context;
use App\Support\Reporting\Csv;
use App\Support\Reporting\Reports;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\DownstreamFixtures;
use Tests\Support\ReportingSchema;
use Tests\TestCase;

class ReportingCohortsTest extends TestCase
{
    use DownstreamFixtures, ReportingSchema;

    public function test_independent_ten_subject_cohort_and_late_recording_do_not_invent_transfers(): void
    {
        // Keep physical fixtures before the real database clock; timezone arithmetic also has fixed Oct-23 unit coverage.
        CarbonImmutable::setTestNow('2026-10-20 20:00:00 UTC');
        Carbon::setTestNow('2026-10-20 20:00:00 UTC');
        try {
            [$admin, $owner, $collector, $point] = $this->allocationSetup();
            $facility = $this->facility($point);
            $receiver = $this->pointStaff($admin, $facility);
            $pointActor = $this->pointStaff($admin, $point);
            $ids = [];
            $requests = [];
            $estimates = ['2.000', '3.000', '4.000', '5.000', '6.000', '7.000', '8.000', '0.500', '0.400', '0.200'];
            for ($n = 0; $n < 10; $n++) {
                $id = $this->device($owner, $n < 7 ? 'laptop' : 'standalone_part', $n < 7 ? null : ['hdd', 'ssd', 'ict_cooling_fan'][$n - 7])->subject_id;
                $ids[] = $id;
                $f = $this->requestFields([$id], 'drop_off');
                $f['estimated_kg'][$id] = $estimates[$n];
                $f['preferred_date'] = '2026-10-23';
                $requests[] = app(CollectionRequests::class)->submit($owner, $f);
            }
            CarbonImmutable::setTestNow('2026-10-22 09:00:00 UTC');
            Carbon::setTestNow('2026-10-22 09:00:00 UTC');
            for ($n = 0; $n < 9; $n++) {
                CarbonImmutable::setTestNow($n < 7 ? '2026-10-21 08:01:00 UTC' : '2026-10-22 09:00:00 UTC');
                Carbon::setTestNow($n < 7 ? '2026-10-21 08:01:00 UTC' : '2026-10-22 09:00:00 UTC');
                $this->ready($admin, $requests[$n]);
                $fields = $this->visitFields($requests[$n]);
                $fields['observed_at'] = $n === 0 ? '2026-10-20T21:00' : ($n === 7 ? '2026-10-21T20:59' : ($n === 8 ? '2026-10-21T21:00' : '2026-10-21T08:00'));
                app(RecordVisit::class)->record($pointActor, $requests[$n], $fields);
                $this->handoverBoundary();
            }
            CarbonImmutable::setTestNow('2026-10-21 09:00:00 UTC');
            Carbon::setTestNow('2026-10-21 09:00:00 UTC');
            $fields = $this->initiateFields($pointActor, $receiver);
            $fields['subjects'] = array_values(array_filter($fields['subjects'], fn ($ref) => in_array(explode(':', $ref)[0], array_slice($ids, 0, 6), true)));
            $fields['observed_at'] = '2026-10-21T09:00';
            $h = app(CustodyHandovers::class)->initiate($pointActor, $fields);
            CarbonImmutable::setTestNow('2026-10-21 09:01:00 UTC');
            Carbon::setTestNow('2026-10-21 09:01:00 UTC');
            $ack = $this->acknowledgeFields($h);
            $ack['observed_at'] = '2026-10-21T09:01';
            app(CustodyHandovers::class)->change($receiver, $h, 'acknowledged', $ack);
            $this->handoverBoundary();
            CarbonImmutable::setTestNow('2026-10-22 09:00:00 UTC');
            Carbon::setTestNow('2026-10-22 09:00:00 UTC');
            $filters = ['from' => '2026-10-21', 'to' => '2026-10-21'];
            $r = app(Reports::class)->snapshot($admin, ['kind' => 'collections'] + $filters);
            $this->assertSame(8, $r['summary']['R01 first received subjects']);
            $this->assertSame(7, $r['summary']['Whole devices']);
            $this->assertSame(1, $r['summary']['Standalone parts']);
            $this->assertSame('35.500', $r['summary']['R04 estimated kg']);
            $this->assertSame('8/8', $r['summary']['Estimate coverage']);
            $this->assertEqualsCanonicalizing(array_slice($ids, 0, 8), array_column($r['rows'], 'id'));
            $f = app(Reports::class)->snapshot($receiver, ['kind' => 'facility'] + $filters);
            $this->assertSame(6, $f['summary']['R02 first-facility subjects']);
            $this->assertEqualsCanonicalizing(array_slice($ids, 0, 6), array_column($f['rows'], 'id'));
            // Two canonical derived media plus C08's storage facet do not enlarge either cohort.
            $base = compact('admin', 'owner', 'collector', 'point', 'facility', 'receiver', 'pointActor');
            $work = $base + ['ids' => [$ids[4]]];
            app(InspectItems::class)->inspect($receiver, $ids[4], $this->inspectionFields($ids[4], 'laptop-healthy'));
            app(InspectItems::class)->decide($receiver, $ids[4], $this->decisionFields($ids[4]));
            $this->identifyMedium($work, 'hdd');
            $this->identifyMedium($work, 'ssd');
            $this->assertSame(8, app(Reports::class)->snapshot($admin, ['kind' => 'collections'] + $filters)['summary']['R01 first received subjects']);
            $this->assertSame(6, app(Reports::class)->snapshot($receiver, ['kind' => 'facility'] + $filters)['summary']['R02 first-facility subjects']);
            // Complete genuine returns; a separate evidence-availability compatibility case retains C06's acknowledgment.
            Storage::fake('directory_evidence');
            $proof = null;
            foreach ([0, 1, 5] as $index) {
                $work = $base + ['ids' => [$ids[$index]]];
                $inspection = $this->inspectionFields($ids[$index], 'laptop-healthy');
                $inspection['answers']['data_status'] = 'NO';
                app(InspectItems::class)->inspect($receiver, $ids[$index], $inspection);
                app(InspectItems::class)->decide($receiver, $ids[$index], $this->decisionFields($ids[$index]));
                $this->inventoryRecord($work, 'attest');
                if ($index === 5) {
                    $fields = $this->reuseFields($work);
                    $fields['evidence_kind'] = 'attachment';
                    unset($fields['reference_issuer'],$fields['reference_code'],$fields['reference_attested']);
                    $record = app(RefurbishItems::class)->record($receiver, $ids[$index], 'work', $fields, UploadedFile::fake()->image('retained-proof.jpg'));
                    $this->handoverBoundary();
                    $proof = DB::table('refurbishment_records')->where('id', $record)->value('attachment_id');
                } else {
                    $this->reuseRecord($work);
                }
                $this->reuseRecord($work, 'test');
                $this->reuseRecord($work, 'authorize');
                $this->reuseRecord($work, 'acknowledge', owner: true);
            }
            $live = app(Reports::class)->snapshot($receiver, ['kind' => 'facility'] + $filters)['live'];
            $this->assertSame(['Consumer-origin physical subjects' => 3, 'Processor-origin physical subjects' => 2], $live);
            foreach ([2, 3] as $index) {
                $work = $base + ['ids' => [$ids[$index]]];
                $inspection = $this->inspectionFields($ids[$index], 'laptop-healthy');
                $inspection['answers']['data_status'] = 'NO';
                app(InspectItems::class)->inspect($receiver, $ids[$index], $inspection);
                app(InspectItems::class)->decide($receiver, $ids[$index], $this->decisionFields($ids[$index], 'recycle'));
                $this->inventoryRecord($work, 'attest');
            }
            $work = $base + ['ids' => [$ids[2], $ids[3]], 'batch' => (string) Str::uuid(), 'media' => []];
            $this->batchRecord($work);
            $this->batchRecord($work, 'commit');
            DB::table('attachments')->where('id', $proof)->update(['redacted_at' => now()]);
            $qualified = app(Reports::class)->snapshot($receiver, ['kind' => 'facility'] + $filters);
            $this->assertSame(6, $qualified['summary']['R02 first-facility subjects']);
            $this->assertSame(2, $qualified['summary']['R03 current qualified reuse returns']);
            $this->assertSame(3, $qualified['summary']['Historical acknowledged returns (overlaps current buckets)']);
            $this->assertSame('33.33%', $qualified['summary']['Current reuse-return rate']);
            $this->assertSame(4, $qualified['summary']['Incomplete current outcomes']);
            $this->assertSame(2, $qualified['summary']['batch_linked_outcome']);
            $this->assertSame(1, $qualified['summary']['other_pending']);
            $this->assertSame(1, $qualified['summary']['current_exception']);
            $this->receiptSignIn($admin);
            $ui = $this->get(route('reports.index', ['kind' => 'collections'] + $filters))->assertOk();
            $displayed = $ui->viewData('snapshot');
            $this->assertSame(8, $displayed['summary']['R01 first received subjects']);
            $this->assertSame('35.500', $displayed['summary']['R04 estimated kg']);
            [$csv] = app(Csv::class)->encode($displayed, microtime(true));
            $stream = fopen('php://temp', 'w+');
            fwrite($stream, $csv);
            rewind($stream);
            $header = fgetcsv($stream, escape: '');
            $summaryCells = [];
            $detailIds = [];
            while (($values = fgetcsv($stream, escape: '')) !== false) {
                $values = array_combine($header, $values);
                if ($values['row_kind'] === 'report_summary_non_additive') {
                    $summaryCells[$values['metric']] = $values['value'];
                } else {
                    $detailIds[] = $values['id'];
                }
            }
            fclose($stream);
            $this->assertSame('8', $summaryCells['R01 first received subjects']);
            $this->assertSame('35.500', $summaryCells['R04 estimated kg']);
            $this->assertEqualsCanonicalizing(array_slice($ids, 0, 8), $detailIds);
            $other = User::factory()->create();
            $this->assertSame(0, app(Reports::class)->snapshot($other, ['kind' => 'collections'] + $filters)['summary']['R01 first received subjects']);
            $this->receiptSignIn($other)->get(route('timeline.show', $ids[0]))->assertNotFound();
            $this->receiptSignIn($admin)->get(route('timeline.show', $ids[0]))->assertForbidden();
        } finally {
            CarbonImmutable::setTestNow();
            Carbon::setTestNow();
        }
    }

    public function test_later_place_reclassification_never_invents_first_facility_admission_or_redistribution(): void
    {
        $w = $this->handoverWork(1, true);
        $otherFacility = $this->facility($w['point']);
        $otherActor = $this->pointStaff($w['admin'], $otherFacility);
        $this->handoverBoundary();
        $h = $this->initiateHandover($w);
        app(CustodyHandovers::class)->change($w['receiver'], $h, 'acknowledged', $this->acknowledgeFields($h));
        $this->handoverBoundary();
        $this->assertNull(app(Context::class)->party($w['receiver']));
        $this->assertTrue(app(Context::class)->held($w['receiver'])->isEmpty());
        $denied = $this->initiateFields($w['receiver'], $otherActor);
        $denied['subjects'] = [$w['ids'][0].':'.DB::table('subjects')->where('id', $w['ids'][0])->value('version')];
        try {
            app(CustodyHandovers::class)->initiate($w['receiver'], $denied);
            $this->fail('Unsupported redistribution was accepted.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertSame(1, app(Reports::class)->snapshot($w['receiver'], ['kind' => 'facility'])['summary']['R02 first-facility subjects']);
        // A separate original point-only subject exercises legacy classification without a transfer.
        $item = $this->device($w['owner'], 'standalone_part', 'ssd')->subject_id;
        $request = $this->readyRequest($w['admin'], $w['owner'], [$item], 'drop_off');
        app(RecordVisit::class)->record($w['pointActor'], $request, $this->visitFields($request));
        $this->handoverBoundary();
        DB::table('places')->where('id', $w['point'])->update(['kind' => 'facility', 'version' => DB::raw('version+1')]);
        $report = app(Reports::class)->snapshot($w['pointActor'], ['kind' => 'facility']);
        $this->assertSame(0, $report['summary']['R02 first-facility subjects']);
        $this->assertSame(0, $report['summary']['Unclassifiable admission evidence (excluded)']);
        $this->assertSame(0, count($report['rows']));
    }

    public function test_read_only_legacy_classification_holds_unknown_ancestry_instead_of_guessing_a_later_facility(): void
    {
        foreach ([['handover_accepted', 'facility', null, false, true, 'facility'], ['handover_accepted', null, null, false, true, 'unknown'], ['first_receipt', null, 'drop_off', true, true, 'collection_point'], ['first_receipt', 'facility', 'drop_off', true, true, 'unknown'], ['first_receipt', null, 'pickup', false, false, 'collector'], ['item_registered', 'facility', null, false, true, 'unknown']] as [$event,$kind,$mode,$point,$place,$expected]) {
            $this->assertSame($expected, DB::selectOne('SELECT reporting_admission_kind(?,?,?,?,?) kind', [$event, $kind, $mode, $point, $place])->kind);
        }
        // Read-only legacy vectors are not supported physical transfer fixtures or new write authority.
        foreach ([[[['version' => 2, 'kind' => 'collector'], ['version' => 3, 'kind' => 'collection_point'], ['version' => 4, 'kind' => 'facility'], ['version' => 5, 'kind' => 'facility']], 4], [[['version' => 2, 'kind' => 'unknown'], ['version' => 3, 'kind' => 'facility']], null], [[['version' => 2, 'kind' => 'collection_point']], null], [[['version' => 2, 'kind' => 'facility'], ['version' => 3, 'kind' => 'unknown']], 2]] as [$facts,$expected]) {
            $actual = DB::selectOne('SELECT reporting_first_facility_version(?::jsonb) version', [json_encode($facts, JSON_THROW_ON_ERROR)])->version;
            $this->assertSame($expected, $actual);
        }
    }

    public function test_real_acknowledged_reuse_is_qualified_after_expected_owner_custody_change(): void
    {
        $w = $this->inventoryWork('NO');
        $this->inventoryRecord($w, 'attest');
        $this->reusePassed($w);
        $this->reuseRecord($w, 'authorize');
        $this->reuseRecord($w, 'acknowledge', owner: true);
        $r = app(Reports::class)->snapshot($w['receiver'], ['kind' => 'facility']);
        $this->assertSame(1, $r['summary']['R02 first-facility subjects']);
        $this->assertSame(1, $r['summary']['R03 current qualified reuse returns']);
        $this->assertSame('100.00%', $r['summary']['Current reuse-return rate']);
        $this->assertSame(1, $r['summary']['Historical acknowledged returns (overlaps current buckets)']);
        $this->receiptSignIn($w['receiver'])->get(route('timeline.show', $w['ids'][0]))->assertNotFound();
        $this->receiptSignIn($w['owner'])->get(route('timeline.show', $w['ids'][0]))->assertOk()->assertSee('Reuse return physically acknowledged')->assertDontSee('Owner acknowledged reuse return');
    }
}
