<?php

namespace Tests\Feature;

use App\Actions\CustodyHandovers;
use App\Actions\RecordVisit;
use App\Actions\RegisterDevice;
use App\Models\User;
use App\Support\Devices\Questions;
use App\Support\NairobiTime;
use App\Support\Notices\Milestones;
use App\Support\Reporting\Csv;
use App\Support\Reporting\Exports;
use App\Support\Reporting\Filters;
use App\Support\Reporting\Reports;
use App\Support\Reporting\Timeline;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\DownstreamFixtures;
use Tests\Support\ReportingSchema;
use Tests\TestCase;

class ReportingTest extends TestCase
{
    use DownstreamFixtures, ReportingSchema;

    public function test_strict_nairobi_entry_preserves_legacy_payload_and_boundaries(): void
    {
        $old = ['observed_at' => '2026-10-22T21:00', 'reason' => 'Retained'];
        $this->assertSame($old, NairobiTime::normalize($old));
        $this->assertEquals($old, NairobiTime::normalize(['observed_at_local' => '2026-10-23T00:00', 'time_basis' => NairobiTime::BASIS, 'reason' => 'Retained']));
        $this->assertSame('2026-10-23T20:59', NairobiTime::localMinute('2026-10-23T23:59'));
        $this->assertSame('2028-02-28T21:00', NairobiTime::localMinute('2028-02-29T00:00'));
        $this->assertSame(['2026-10-22 21:00:00', '2026-10-23 21:00:00'], Filters::bounds(['from' => '2026-10-23', 'to' => '2026-10-23']));
        foreach ([['observed_at_local' => "2026-10-23T00:00\0", 'time_basis' => NairobiTime::BASIS], ['observed_at_local' => '2026-02-29T12:00', 'time_basis' => NairobiTime::BASIS], ['observed_at_local' => '2026-10-23T00:00+03:00', 'time_basis' => NairobiTime::BASIS], ['observed_at_local' => '2026-10-23T00:00'], $old + ['observed_at_local' => '2026-10-23T00:00', 'time_basis' => NairobiTime::BASIS]] as $bad) {
            try {
                NairobiTime::normalize($bad);
                $this->fail('Invalid/mixed entry accepted.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_actual_receipts_and_first_admissions_do_not_count_same_id_facets_or_pending_transfers(): void
    {
        $w = $this->handoverWork(2, true);
        $report = app(Reports::class)->snapshot($w['admin'], ['kind' => 'collections']);
        $this->assertSame(2, $report['summary']['R01 first received subjects']);
        $this->assertSame('0.500', $report['summary']['R04 estimated kg']);
        $this->assertSame('2/2', $report['summary']['Estimate coverage']);
        $this->assertEqualsCanonicalizing($w['ids'], array_column($report['rows'], 'id'));
        $before = app(Reports::class)->snapshot($w['admin'], ['kind' => 'facility']);
        $this->assertSame(0, $before['summary']['R02 first-facility subjects']);
        $this->assertSame('Not applicable (0 received)', $before['summary']['Current reuse-return rate']);
        $h = $this->initiateHandover($w);
        app(CustodyHandovers::class)->change($w['receiver'], $h, 'acknowledged', $this->acknowledgeFields($h));
        $this->handoverBoundary();
        $after = app(Reports::class)->snapshot($w['receiver'], ['kind' => 'facility']);
        $this->assertSame(2, $after['summary']['R02 first-facility subjects']);
        $this->assertSame(2, $after['summary']['Incomplete current outcomes']);
        $this->assertSame('0.00%', $after['summary']['Current reuse-return rate']);
        $this->actingAs($w['owner'])->withSession(['auth_session_version' => $w['owner']->session_version])->get(route('reports.index'))->assertOk()->assertSee('0.500');
        $this->get(route('timeline.show', $w['ids'][0]))->assertOk()->assertSee('Physical handover acknowledged')->assertSee('Outcome pending')->assertDontSee('Private synthetic physical');
    }

    public function test_known_batch_stock_and_export_share_revision_and_invalidate_after_source_change(): void
    {
        $w = $this->downstreamWork();
        $snapshot = app(Reports::class)->snapshot($w['receiver'], ['kind' => 'batches']);
        $this->assertSame('10.000', $snapshot['summary']['R05 measured input kg']);
        $this->assertSame('10.000', $snapshot['summary']['produced kg (known outputs)']);
        $this->assertSame('10.000', $snapshot['summary']['accounted kg (known outputs)']);
        $h = $this->shipmentDraft($w, ['kg' => '3.000']);
        $this->downstreamRecord($w, 'reserve', $h, overrides: ['kg' => '3.000']);
        $this->downstreamRecord($w, 'withdraw', $h, overrides: ['kg' => '1.000']);
        $this->downstreamRecord($w, 'dispatch', $h, overrides: ['kg' => '2.000']);
        $snapshot = app(Reports::class)->snapshot($w['receiver'], ['kind' => 'batches']);
        $this->assertSame('8.000', $snapshot['summary']['accounted kg (known outputs)']);
        $this->assertSame('0.000', $snapshot['summary']['reserved kg (known outputs)']);
        $service = app(Exports::class);
        $id = $service->generate($w['receiver'], $snapshot['filters'], $snapshot['revision']);
        try {
            $bytes = $service->retrieve($w['receiver'], $id);
            $this->assertStringContainsString($snapshot['revision'], $bytes);
            $this->assertStringContainsString('8.000', $bytes);
            $this->assertNull($service->retrieve($w['recipient'], $id));
            $this->downstreamRecord($w, 'accept', $h, true, ['kg' => '2.000']);
            $this->assertNull($service->retrieve($w['receiver'], $id));
            $this->assertSame('invalidated', DB::table('report_exports')->where('id', $id)->value('state'));
        } finally {
            @unlink($service->path($id.'.csv'));
        }
    }

    public function test_versioned_notices_are_deduplicated_and_old_notification_identity_is_retained(): void
    {
        $owner = User::factory()->create();
        $item = $this->device($owner);
        $this->handoverBoundary();
        $row = DB::table('notification_outbox')->where('recipient_id', $owner->id)->first();
        $this->assertSame('C17-1', $row->delivery_version);
        $this->assertSame(1, app(Milestones::class)->deliver());
        $this->assertSame(0, app(Milestones::class)->deliver());
        $notice = DB::table('milestone_notifications')->first();
        $this->actingAs($owner)->withSession(['auth_session_version' => $owner->session_version])->post(route('inbox.read', ['milestone', $notice->id]))->assertRedirect();
        $read = DB::table('milestone_notifications')->where('id', $notice->id)->value('read_at');
        $this->get(route('inbox.index'))->assertOk()->assertSee('Item registered')->assertSee('Read');
        $this->assertSame($read, DB::table('milestone_notifications')->where('id', $notice->id)->value('read_at'));
        $this->assertSame(1, DB::table('milestone_notifications')->count());
    }

    public function test_timeline_cursor_ties_and_late_insert_never_duplicate_the_original_event_set(): void
    {
        try {
            $this->travelTo(CarbonImmutable::parse('2026-10-20T10:00:00Z'));
            $owner = User::factory()->create();
            $item = $this->device($owner);
            $expected = [DB::table('domain_events')->where('subject_id', $item->subject_id)->value('id')];
            $base = ['data_status' => 'UNKNOWN', 'physical_damage' => 'UNKNOWN', 'safety_concern' => 'NO', 'reason' => 'Synthetic retained self-report', 'answers' => array_fill_keys(array_keys(Questions::tests('laptop', null)), 'UNKNOWN')];
            for ($version = 2; $version <= 30; $version++) {
                $item = app(RegisterDevice::class)->revise($owner, $item, $base + ['idempotency_key' => (string) Str::uuid(), 'expected_version' => $version - 1]);
                $expected[] = DB::table('domain_events')->where('subject_id', $item->subject_id)->where('aggregate_version', $version)->value('id');
            }
            $this->handoverBoundary();
            $reader = app(Timeline::class);
            $first = $reader->events($owner, $item->subject_id);
            $this->assertCount(25, $first->items());
            $cursor = $first->nextCursor()->encode();
            $this->assertSame(1, DB::table('domain_events')->where('subject_id', $item->subject_id)->distinct()->count('recorded_at'));
            $this->travel(1)->minutes();
            $item = app(RegisterDevice::class)->revise($owner, $item, $base + ['idempotency_key' => (string) Str::uuid(), 'expected_version' => 30]);
            $this->handoverBoundary();
            $late = DB::table('domain_events')->where('subject_id', $item->subject_id)->where('aggregate_version', 31)->value('id');
            $second = $reader->events($owner, $item->subject_id, $cursor);
            $this->assertCount(5, $second->items());
            $ids = array_merge(array_column($first->items(), 'id'), array_column($second->items(), 'id'));
            $this->assertCount(30, array_unique($ids));
            $this->assertEqualsCanonicalizing($expected, $ids);
            $this->assertNotContains($late, $ids);
            $this->actingAs($owner)->withSession(['auth_session_version' => $owner->session_version])->get(route('timeline.show', [$item->subject_id, 'cursor' => $cursor]))->assertOk()->assertSee('Newer events');
            $bad = (new Cursor(['recorded_at' => 'invalid', 'id' => $late]))->encode();
            $this->get(route('timeline.show', [$item->subject_id, 'cursor' => $bad]))->assertStatus(422);
        } finally {
            $this->travelBack();
        }
    }

    public function test_legacy_receipt_retry_and_versioned_local_retry_have_identical_canonical_hash(): void
    {
        $w = $this->handoverWork(1, true);
        $item = $this->device($w['owner'], 'standalone_part', 'hdd');
        $request = $this->readyRequest($w['admin'], $w['owner'], [$item->subject_id], 'drop_off');
        $old = $this->visitFields($request);
        $visit = app(RecordVisit::class)->record($w['pointActor'], $request, $old);
        $this->handoverBoundary();
        $count = DB::table('domain_events')->count();
        $outbox = DB::table('notification_outbox')->count();
        $local = $old;
        $local['observed_at_local'] = CarbonImmutable::parse($old['observed_at'], 'UTC')->timezone('Africa/Nairobi')->format('Y-m-d\TH:i');
        $local['time_basis'] = NairobiTime::BASIS;
        unset($local['observed_at']);
        $this->assertSame($visit, app(RecordVisit::class)->record($w['pointActor'], $request, $local));
        $this->assertSame($count, DB::table('domain_events')->count());
        $this->assertSame($outbox, DB::table('notification_outbox')->count());
        $this->receiptSignIn($w['pointActor']);
        $malformed = $local;
        $malformed['time_basis'] = ' africa_nairobi_v1 ';
        $this->post(route('receipts.store', $request), $malformed)->assertSessionHasErrors('observed_at_local');
        $malformed = $local;
        $malformed['observed_at_local'] .= "\0";
        $this->post(route('receipts.store', $request), $malformed)->assertSessionHasErrors('observed_at_local');

        $local['observed_at_local'] = CarbonImmutable::parse($local['observed_at_local'])->subMinute()->format('Y-m-d\TH:i');
        try {
            app(RecordVisit::class)->record($w['pointActor'], $request, $local);
            $this->fail('Changed normalized command was accepted.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
    }

    public function test_csv_untrusted_text_and_signed_numeric_variance_are_distinct(): void
    {
        foreach (['=1+1', ' +CMD', '-cmd', '@SUM(A1)', "\tformula", "\n=cmd", "\u{00A0}=1"] as $value) {
            $this->assertStringStartsWith("'", Csv::text($value));
        }
        $this->assertSame('Nairobi, "Kenya"', Csv::text('Nairobi, "Kenya"'));
        $this->assertSame('safevalue', Csv::text("safe\0value"));
        [$bytes] = app(Csv::class)->encode(['filters' => ['kind' => 'batches', 'from' => '2026-10-23', 'to' => '2026-10-23', 'category' => '', 'subtype' => '', 'facility' => ''], 'definition' => 'C17-1.0', 'revision' => str_repeat('a', 64), 'generated_at' => '2026-10-23T09:00:00Z', 'summary' => [], 'rows' => [['row_kind' => 'batch_summary', 'variance_kg' => '-1.000', 'material' => '=1+1']]], microtime(true));
        $this->assertStringContainsString('-1.000', $bytes);
        $this->assertStringContainsString("'=1+1", $bytes);
        $this->assertStringNotContainsString("'-1.000", $bytes);
    }
}
