<?php

namespace Tests\Feature;

use App\Actions\AllocateCollector;
use App\Actions\CustodyHandovers;
use App\Actions\InspectItems;
use App\Actions\RecordVisit;
use App\Models\User;
use App\Support\Reporting\Exports;
use App\Support\Reporting\Reports;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\ReportingSchema;
use Tests\Support\RestrictionFixtures;
use Tests\TestCase;

class ReportingRolesTest extends TestCase
{
    use ReportingSchema, RestrictionFixtures;

    public function test_live_two_requests_three_assignments_and_only_current_accepted_unconsumed_manifest(): void
    {
        [$admin,$owner,$collector,$route,$ordered] = $this->pickupWork(2);
        $other = User::factory()->create();
        $point = DB::table('assignments')->where('id', $ordered[0])->value('facility_id');
        $request = $this->readyRequest($admin, $other);
        app(AllocateCollector::class)->allocate($admin, $request, $this->offerFields($request, $collector, $point));
        $reports = app(Reports::class);
        $this->assertSame(['Outstanding requests' => 2], $reports->snapshot($owner, [])['live']);
        $manifest = $reports->snapshot($collector, []);
        $this->assertSame(['Offered assignments (not scheduled)' => 1, 'Accepted assignments (not a booking)' => 2], $manifest['live']);
        $this->assertCount(2, $manifest['rows']);
        $expected = DB::table('assignments as a')->join('requests as r', 'r.id', '=', 'a.request_id')->whereIn('a.id', $ordered)->pluck('r.public_code')->all();
        $this->assertEqualsCanonicalizing($expected, array_column($manifest['rows'], 'public_code'));
        $offerCode = DB::table('requests')->where('id', $request)->value('public_code');
        $this->assertNotContains($offerCode, array_column($manifest['rows'], 'public_code'));
        foreach ($manifest['rows'] as $row) {
            $this->assertSame('Synthetic contact only', $row['contact']);
            $this->assertSame(1, $row['item_count']);
            $this->assertArrayNotHasKey('identifier_ciphertext', $row);
        }
        $this->receiptSignIn($collector);
        $this->withCookie(config('session.cookie'), app('session')->getId());
        $displayed = $this->get(route('reports.index'))->assertOk()->viewData('snapshot');
        $this->post(route('reports.export'), $displayed['filters'] + ['revision' => $displayed['revision']])->assertRedirect();
        $id = DB::table('report_exports')->where('requester_id', $collector->id)->value('id');
        try {
            $record = DB::table('report_exports')->where('id', $id)->firstOrFail();
            $this->assertSame(30.0, CarbonImmutable::parse($record->expires_at)->diffInMinutes($record->generated_at, true));
            $this->receiptSignIn($collector)->get(route('reports.index'))->assertOk()->assertSee('Current Collector manifest');
            $response = $this->get(route('reports.download', $id));
            $response->assertOk();
            ob_start();
            $response->baseResponse->sendContent();
            $csv = ob_get_clean();
            $this->assertStringContainsString('Synthetic contact only', $csv);
            $this->assertStringNotContainsString($offerCode, $csv);
            $collected = DB::table('assignments')->where('id', $ordered[0])->value('request_id');
            app(RecordVisit::class)->record($collector, $collected, $this->visitFields($collected));
            $this->handoverBoundary();
            $this->assertCount(1, $reports->snapshot($collector, [])['rows']);
            $this->assertNull(app(Exports::class)->retrieve($collector, $id));
            $this->assertSame('invalidated', DB::table('report_exports')->where('id', $id)->value('state'));
            DB::table('organizations')->where('id', $collector->membership->organization_id)->update(['review_status' => 'pending', 'version' => DB::raw('version+1')]);
            $this->get(route('reports.index'))->assertForbidden();
        } finally {
            @unlink(app(Exports::class)->path($id.'.csv'));
        }
    }

    public function test_mixed_category_batch_mass_is_excluded_without_proportional_allocation(): void
    {
        $w = $this->batchWork(0);
        $fan = $this->device($w['owner'], 'standalone_part', 'ict_cooling_fan')->subject_id;
        $request = $this->readyRequest($w['admin'], $w['owner'], [$fan], 'drop_off');
        app(RecordVisit::class)->record($w['pointActor'], $request, $this->visitFields($request));
        $this->handoverBoundary();
        $transfer = $w;
        $transfer['sender'] = $w['pointActor'];
        $transfer['ids'] = [$fan];
        $h = $this->initiateHandover($transfer);
        app(CustodyHandovers::class)->change($w['receiver'], $h, 'acknowledged', $this->acknowledgeFields($h));
        $this->handoverBoundary();
        app(InspectItems::class)->inspect($w['receiver'], $fan, $this->inspectionFields($fan, 'ict_cooling_fan-healthy'));
        app(InspectItems::class)->decide($w['receiver'], $fan, $this->decisionFields($fan, 'recycle'));
        $this->handoverBoundary();
        $w['ids'][] = $fan;
        $this->batchRecord($w);
        $this->batchRecord($w, 'commit');
        $all = app(Reports::class)->snapshot($w['receiver'], ['kind' => 'batches']);
        $this->assertSame('10.000', $all['summary']['R05 measured input kg']);
        $filtered = app(Reports::class)->snapshot($w['receiver'], ['kind' => 'batches', 'category' => 'laptop']);
        $this->assertSame(1, $filtered['summary']['Excluded mixed-category batches']);
        $this->assertSame('0.000', $filtered['summary']['R05 measured input kg']);
        $this->assertSame(0, $filtered['summary']['Batches']);
        $this->assertSame('excluded_mixed_category', $filtered['rows'][0]['row_kind']);
    }

    public function test_three_active_cases_exclude_two_closed_cases_from_admin_live_counter(): void
    {
        $work = [];
        for ($n = 0; $n < 5; $n++) {
            $w = $this->restrictionWork();
            $case = $this->openRestriction($w);
            if ($n >= 3) {
                $this->restrictionRecord($w, $case, 'close');
            }
            $work[] = $w;
        }
        $snapshot = app(Reports::class)->snapshot($work[0]['admin'], []);
        $this->assertSame(['Active restriction cases' => 3], $snapshot['live']);
        $this->assertSame(5, DB::table('restriction_cases')->count());
    }
}
