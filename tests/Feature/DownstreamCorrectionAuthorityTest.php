<?php

namespace Tests\Feature;

use App\Actions\AcceptDownstreamResponsibility;
use App\Actions\ManagePointStaff;
use App\Support\Restrictions\Notices;
use App\Support\Sanitization\Context;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DownstreamCorrectionFixtures;
use Tests\TestCase;

class DownstreamCorrectionAuthorityTest extends TestCase
{
    use DatabaseTransactions, DownstreamCorrectionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (glob(database_path('migrations/2026_10_22_*.php')) as $file) {
            if (! DB::table('migrations')->where('migration', basename($file, '.php'))->exists()) {
                (require $file)->up();
            }
        }
    }

    public function test_whole_media_incident_preserves_custody_and_invalidates_live_assurance(): void
    {
        $w = $this->downstreamWork(true);
        $medium = $w['output'];
        $custody = DB::table('custody_current')->where('subject_id', $medium)->first();
        $evidence = DB::table('sanitization_records')->where('subject_id', $medium)->get();
        $loss = $this->downstreamRecord($w, 'source_loss');
        $this->assertTrue(DB::selectOne('SELECT downstream_media_uncontrolled(?) AS blocked', [$medium])->blocked);
        $this->assertNotSame('satisfied', app(Context::class)->state($medium));
        $this->correction($w, $loss, 'loss_confirm', [$this->fact('downstream', $loss, 'loss_status', 'confirmed')], false, ['quantified_loss' => 'YES']);
        $this->assertFalse(DB::selectOne('SELECT downstream_closable(?) AS ready', [$w['batch']])->ready);
        $this->assertEquals($custody, DB::table('custody_current')->where('subject_id', $medium)->first());
        $this->assertEquals($evidence, DB::table('sanitization_records')->where('subject_id', $medium)->get());
        $this->assertSame(0, DB::table('downstream_records')->where('kind', 'accept')->count());
    }

    public function test_successor_after_a_real_drive_receipt_reuses_that_receipt_without_replaying_custody(): void
    {
        $w = $this->downstreamWork(true);
        $successor = $this->pointStaff($w['admin'], $w['destination']);
        $this->handoverBoundary();
        $h = $this->shipmentDraft($w);
        $this->downstreamRecord($w, 'reserve', $h);
        $this->downstreamRecord($w, 'dispatch', $h);
        $receipt = $this->downstreamRecord($w, 'accept', $h, true);
        $this->downstreamAuthorize($w, $w['recipient']);
        $this->mediaRecord(array_replace($this->mediumWork($w, $w['output']), ['receiver' => $w['recipient']]), 'revalidation');
        $custody = DB::table('custody_current')->where('subject_id', $w['output'])->first();
        $source = DB::table('downstream_shipments')->where('id', $h)->value('created_record_id');
        $this->correction($w, $source, 'recipient', [], false, extra: ['receiver_actor_id' => $successor->id, 'profile_record_id' => $w['profile_record']]);
        $this->receiptSignIn($successor)->get(route('downstream.shipment', $h))->assertOk()->assertSee('Accept recipient responsibility');
        $action = app(AcceptDownstreamResponsibility::class);
        $action->record($successor, $h, 'accepted', $this->handoverEvidence() + ['attested' => '1', 'expected_version' => 1, 'expected_context' => $action->token($successor, $h), 'reason' => 'Synthetic successor accepts already received unit responsibility']);
        $this->handoverBoundary();
        $this->receiptSignIn($w['recipient'])->get(route('downstream.shipment', $h))->assertNotFound();
        $w['recipient'] = $successor;
        $cases = $this->downstreamContinuities($w, false);
        $this->downstreamAuthorize($w, $successor);
        $this->mediaRecord(array_replace($this->mediumWork($w, $w['output']), ['receiver' => $successor]), 'revalidation');
        $this->fulfillDownstream($w, $cases);
        $this->downstreamRecord($w, 'complete', $h, true);
        $this->assertEquals($custody, DB::table('custody_current')->where('subject_id', $w['output'])->first());
        $this->assertSame(1, DB::table('downstream_records')->where('kind', 'accept')->count());
        $this->assertSame($receipt, DB::table('media_authorizations')->orderByDesc('version')->value('downstream_receipt_id'));
        $this->assertSame(4, (int) DB::table('media_authorizations')->orderByDesc('version')->value('authorization_context_version'));
    }

    public function test_revoked_then_restored_technical_author_needs_fresh_independent_obligation_signatures(): void
    {
        $w = $this->downstreamWork();
        $h = $this->shipmentDraft($w);
        $this->downstreamRecord($w, 'reserve', $h);
        $this->downstreamRecord($w, 'dispatch', $h);
        $short = $this->downstreamRecord($w, 'shortage', $h, true);
        $case = $this->correction($w, $short, 'report_error', [$this->fact('downstream', $short, 'active', '0')], facts: ['original_error' => 'YES']);
        $this->downstreamContinuities($w);
        $proof = DB::table('restriction_cases')->where('id', $case)->value('record_id');
        $this->assertTrue(DB::selectOne('SELECT downstream_proof_current(?) AS ready', [$proof])->ready);
        $staff = app(ManagePointStaff::class);
        $actor = $w['recipient'];
        $membership = $actor->membership()->first();
        $staff->save($w['admin'], $actor->id, ['operation' => 'deactivate', 'expected_version' => $membership->version, 'reason' => 'Synthetic current-scope revocation', 'idempotency_key' => (string) Str::uuid()]);
        $this->handoverBoundary();
        $staff->save($w['admin'], $actor->id, ['operation' => 'link', 'point_ref' => $w['destination'].':'.DB::table('places')->where('id', $w['destination'])->value('version'), 'expected_version' => $membership->version + 1, 'reason' => 'Synthetic restored scope requires fresh signatures', 'idempotency_key' => (string) Str::uuid()]);
        $this->handoverBoundary();
        $w['recipient'] = $actor->fresh();
        $this->assertFalse(DB::selectOne('SELECT downstream_proof_current(?) AS ready', [$proof])->ready);
        $this->assertTrue(DB::selectOne('SELECT restriction_dependent(?) AS blocked', [$w['batch']])->blocked);
        $this->downstreamContinuities($w);
        $this->assertTrue(DB::selectOne('SELECT downstream_proof_current(?) AS ready', [$proof])->ready);
        $this->assertSame('0', DB::selectOne("SELECT downstream_fact('downstream',?,'active','1') AS fact", [$short])->fact);
        $this->assertSame('10.000', DB::table('downstream_records')->where('id', $short)->value('kg'));
    }

    public function test_cancellation_does_not_revive_obsolete_recipient_and_notices_remain_private(): void
    {
        $w = $this->downstreamWork();
        $successor = $this->pointStaff($w['admin'], $w['destination']);
        $this->handoverBoundary();
        $h = $this->shipmentDraft($w);
        $this->downstreamRecord($w, 'reserve', $h);
        $this->downstreamRecord($w, 'dispatch', $h);
        $source = DB::table('downstream_shipments')->where('id', $h)->value('created_record_id');
        $case = $this->correction($w, $source, 'recipient', [], false, extra: ['receiver_actor_id' => $successor->id, 'profile_record_id' => $w['profile_record']]);
        $unrelated = DB::table('notification_outbox')->whereNotIn('event_id', DB::table('restriction_records')->select('event_id'))->get();
        app(Notices::class)->deliver();
        $this->assertEquals($unrelated, DB::table('notification_outbox')->whereNotIn('event_id', DB::table('restriction_records')->select('event_id'))->get());
        $this->receiptSignIn($successor)->get(route('restrictions.notices'))->assertOk()->assertSee(route('downstream.shipment', $h), false);
        $this->receiptSignIn($w['owner'])->get(route('restrictions.show', $case))->assertNotFound();
        $action = app(AcceptDownstreamResponsibility::class);
        $fields = $this->handoverEvidence() + ['attested' => '1', 'expected_version' => 1, 'expected_context' => $action->token($w['admin'], $h), 'reason' => 'Synthetic cancellation retains obsolete authority invalidation'];
        $id = $action->record($w['admin'], $h, 'cancelled', $fields);
        $this->handoverBoundary();
        $this->assertSame($id, $action->record($w['admin'], $h, 'cancelled', $fields));
        $this->assertNull(DB::table('downstream_effective_shipments')->where('id', $h)->value('receiver_actor_id'));
        $this->receiptSignIn($w['recipient'])->get(route('downstream.shipment', $h))->assertNotFound();
        $this->receiptSignIn($successor)->get(route('downstream.shipment', $h))->assertNotFound();
        $this->assertSame(0, DB::table('downstream_records')->where('kind', 'accept')->count());
    }
}
