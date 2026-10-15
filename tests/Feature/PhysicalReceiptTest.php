<?php

namespace Tests\Feature;

use App\Actions\CollectionRequests;
use App\Actions\ManagePointStaff;
use App\Actions\PlanRoutes;
use App\Actions\RecordVisit;
use App\Actions\RegisterDevice;
use App\Models\User;
use App\Support\Devices\Questions;
use App\Support\Receipts\Notices;
use App\Support\Routing\Execution;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ReceiptFixtures;
use Tests\TestCase;

class PhysicalReceiptTest extends TestCase
{
    use DatabaseTransactions,ReceiptFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1]);
    }

    public function test_two_stop_receipts_preserve_frozen_run_and_release_only_assignment_claims_until_finish(): void
    {
        [$admin,$owner,$collector,$route,$ordered] = $this->pickupWork(2);
        $run = DB::table('routes')->where('id', $route)->value('run_id');
        $frozen = DB::table('route_runs')->where('id', $run)->first();
        $this->receiptSignIn($collector)->get(route('collector.routes.show', $route))->assertOk()->assertSee('Record pickup visit')->assertSee('Finish tour work');
        $unrelated = DB::table('notification_outbox')->where('state', 'pending')->pluck('id')->all();
        $visits = [];
        foreach ($ordered as $a) {
            $request = DB::table('assignments')->where('id', $a)->value('request_id');
            $fields = $this->visitFields($request);
            $visit = app(RecordVisit::class)->record($collector, $request, $fields);
            $visits[] = $visit;
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
            $counts = DB::table('first_receipts')->count();
            $this->assertSame($visit, app(RecordVisit::class)->record($collector, $request, $fields));
            $this->assertSame($counts, DB::table('first_receipts')->count());
            $this->assertSame('completed', DB::table('assignments')->where('id', $a)->value('state'));
            $this->assertSame('COMPLETED', DB::table('requests')->where('id', $request)->value('state'));
            $this->assertTrue(app(Execution::class)->current($run));
            $this->get(route('collector.routes.show', $route))->assertOk()->assertSee('Recorded outcomes');
            $this->assertFalse(DB::table('route_claims')->where('kind', 'assignment')->where('record_id', $a)->exists());
            $this->assertDatabaseCount('storage_assets', 2);
        }
        $this->get(route('collector.routes.show', $route))->assertOk()->assertDontSee('-1.280000')->assertSee('No remaining pickup coordinates');
        $this->assertEquals($frozen, DB::table('route_runs')->where('id', $run)->first());
        $this->assertDatabaseCount('route_claims', 2);
        foreach (DB::table('first_receipts')->get() as $f) {
            $this->assertSame($owner->id, DB::table('items')->where('subject_id', $f->subject_id)->value('owner_user_id'));
            $this->assertSame('YES', DB::table('items')->where('subject_id', $f->subject_id)->value('data_status'));
            $this->assertSame($collector->id, DB::table('parties')->where('id', $f->recipient_party_id)->value('user_id'));
            $this->assertSame($f->event_id, DB::table('custody_current')->where('subject_id', $f->subject_id)->value('last_event_id'));
        }
        $this->assertSame(2, app(Notices::class)->deliver());
        $this->assertSame(0, app(Notices::class)->deliver());
        $this->assertSame(count($unrelated), DB::table('notification_outbox')->whereIn('id', $unrelated)->where('state', 'pending')->count());
        $finish = ['idempotency_key' => Str::uuid()->toString(), 'unfinished' => [], 'confirm' => '1', 'reason' => 'Synthetic tour work ended, no delivery claim'];
        $this->assertSame($route, app(Execution::class)->finish($collector, $route, $finish));
        $this->assertSame($route, app(Execution::class)->finish($collector, $route, $finish));
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $this->assertDatabaseCount('route_claims', 0);
        $this->assertSame('finished', DB::table('route_approvals')->where('run_id', $run)->value('state'));
        $this->receiptSignIn($owner)->get(route('receipts.result', $visits[0]))->assertOk()->assertDontSee('Private synthetic physical');
        $this->get(route('requests.show', DB::table('receipt_visits')->where('id', $visits[0])->value('request_id')))->assertOk()->assertSee('View safe visit outcomes');
    }

    public function test_partial_visit_distinguishes_absent_refused_hold_mismatch_and_untouched_then_requires_review(): void
    {
        [$admin,$owner,$collector,$place,$depot,$vehicle] = $this->routingSetup();
        $items = [];
        for ($i = 0; $i < 6; $i++) {
            $items[] = $this->device($owner)->subject_id;
        }
        $a = $this->accepted($admin, $owner, $collector, $place, $items);
        $run = app(PlanRoutes::class)->create($admin, $this->routeFields($depot, $vehicle, [$a]));
        app(PlanRoutes::class)->approve($admin, $run, $this->approvalFields());
        $r = DB::table('assignments')->where('id', $a)->value('request_id');
        $fields = $this->visitFields($r, ['received', 'absent', 'refused', 'mismatch', 'safety_hold', 'untouched']);
        $visit = app(RecordVisit::class)->record($collector, $r, $fields);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $this->assertDatabaseCount('receipt_outcomes', 5);
        $this->assertDatabaseCount('first_receipts', 1);
        $this->assertDatabaseCount('receipt_discrepancies', 2);
        $this->assertSame(5, DB::table('request_items')->where('active', true)->count());
        $this->assertSame('PARTIALLY_COMPLETED', DB::table('requests')->where('id', $r)->value('state'));
        $this->assertSame('stale', DB::table('requests')->where('id', $r)->value('review_state'));
        $this->assertSame('remainder_review', DB::table('assignments')->where('id', $a)->value('state'));
        $this->receiptSignIn($collector)->get(route('receipts.form', $r))->assertStatus(409);
        $this->assertSame($visit, app(RecordVisit::class)->record($collector, $r, $fields));
    }

    public function test_dropoff_scope_is_explicit_revoked_sessions_and_owner_privacy_are_enforced(): void
    {
        [$admin,$owner,$collector,$place] = $this->allocationSetup();
        $staff = $this->pointStaff($admin, $place);
        $r = $this->readyRequest($admin, $owner, [], 'drop_off');
        $this->receiptSignIn($staff)->get(route('processor.intake'))->assertOk()->assertSee('Open drop-off visit');
        $this->get(route('receipts.form', $r))->assertOk()->assertSee('Record drop-off visit')->assertDontSee('Synthetic contact only');
        $visit = app(RecordVisit::class)->record($staff, $r, $this->visitFields($r));
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $f = DB::table('first_receipts')->first();
        $this->assertSame($place, DB::table('parties')->where('id', $f->recipient_party_id)->value('place_id'));
        $this->assertSame($staff->id, $f->actor_id);
        $other = User::factory()->create();
        $this->receiptSignIn($other)->get(route('receipts.result', $visit))->assertNotFound();
        $this->receiptSignIn($admin)->get(route('receipts.form', $r))->assertForbidden();
        app(ManagePointStaff::class)->save($admin, $staff->id, ['operation' => 'deactivate', 'expected_version' => $staff->membership->version, 'reason' => 'Synthetic revoke', 'idempotency_key' => Str::uuid()->toString()]);
        $this->assertGreaterThan($staff->session_version, $staff->fresh()->session_version);
        $this->receiptSignIn($staff->fresh())->get(route('receipts.evidence', $visit))->assertRedirect(route('login'));
    }

    public function test_contextual_lookup_and_validation_do_not_disclose_or_flash_private_information(): void
    {
        [$admin,$owner,$collector,$route,$ordered] = $this->pickupWork();
        $r = DB::table('assignments')->where('id', $ordered[0])->value('request_id');
        $outsider = User::factory()->create();
        $foreign = $this->device($outsider);
        $this->receiptSignIn($collector)->post(route('receipts.lookup', $r), ['code' => $foreign->subject->public_code])->assertSessionHasErrors('code');
        $this->assertDatabaseCount('receipt_discrepancies', 1);
        $code = $this->visitFields($r)['items'][0]['code'];
        $this->post(route('receipts.lookup', $r), ['code' => $code])->assertRedirect(route('receipts.form', $r));
        $this->get(route('items.lookup', $code))->assertOk()->assertSee('Choose authorized work')->assertDontSee($code);
        $fields = $this->visitFields($r);
        $fields['items'][0]['attested'] = '0';
        $this->post(route('receipts.store', $r), $fields)->assertSessionHasErrors('items')->assertSessionMissing('_old_input.items')->assertSessionMissing('_old_input.reference_code');
        $this->assertDatabaseCount('first_receipts', 0);
        $fields = $this->visitFields($r);
        $fields['items'][0]['recipient_party_id'] = null;
        $this->postJson(route('receipts.store', $r), $fields)->assertUnprocessable();
        $this->receiptSignIn($outsider)->get(route('receipts.form', $r))->assertForbidden();
    }

    public function test_real_eligibility_change_invalidates_remaining_route_after_first_receipt(): void
    {
        [$admin,$owner,$collector,$route,$ordered] = $this->pickupWork(2);
        $first = DB::table('assignments')->where('id', $ordered[0])->value('request_id');
        app(RecordVisit::class)->record($collector, $first, $this->visitFields($first));
        $r = DB::table('assignments')->where('id', $ordered[1])->value('request_id');
        $row = DB::table('request_items')->where('request_id', $r)->first();
        app(CollectionRequests::class)->change($owner, $r, ['operation' => 'date', 'items' => [$row->item_id], 'preferred_date' => now()->addDays(2)->toDateString(), 'expected_version' => DB::table('requests')->where('id', $r)->value('version'), 'reason' => 'Synthetic changed preference', 'idempotency_key' => Str::uuid()->toString()]);
        $this->assertSame('stale', DB::table('route_approvals')->value('state'));
        $this->receiptSignIn($collector)->get(route('receipts.form', $r))->assertStatus(409);
        $this->assertDatabaseCount('first_receipts', 1);
        $this->assertDatabaseCount('route_claims', 0);
    }

    public function test_finish_with_unfinished_stops_releases_claims_without_receipt_or_attempt(): void
    {
        [$admin,$owner,$collector,$route,$ordered] = $this->pickupWork(2);
        sort($ordered);
        $this->receiptSignIn($collector)->post(route('collector.routes.finish', $route), ['idempotency_key' => Str::uuid()->toString(), 'confirm' => '1', 'reason' => 'Synthetic finish', 'unfinished' => []])->assertStatus(409);
        $this->post(route('collector.routes.finish', $route), ['idempotency_key' => Str::uuid()->toString(), 'confirm' => '1', 'reason' => 'Synthetic finish', 'unfinished' => $ordered])->assertRedirect(route('collector.routes.index'));
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $this->assertDatabaseCount('first_receipts', 0);
        $this->assertDatabaseCount('receipt_visits', 0);
        $this->assertDatabaseCount('route_claims', 0);
        $this->assertSame(2, DB::table('assignments')->where('state', 'accepted')->where('active', true)->count());
        $this->assertSame(2, DB::table('request_items')->where('active', true)->count());
    }

    public function test_failure_after_upload_rolls_back_every_projection_and_cleans_only_new_file(): void
    {
        Storage::fake('directory_evidence');
        Storage::disk('directory_evidence')->put('existing-private', 'retain');
        [$admin,$owner,$collector,$route,$ordered] = $this->pickupWork();
        $r = DB::table('assignments')->where('id', $ordered[0])->value('request_id');
        $fields = $this->visitFields($r);
        $fields['evidence_kind'] = 'attachment';
        unset($fields['reference_code'],$fields['reference_issuer'],$fields['reference_attested']);
        $before = DB::table('custody_current')->get()->toJson();
        $commands = DB::table('commands')->count();
        DB::unprepared("CREATE FUNCTION fail_physical_test() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Injected failure'; END; $$; CREATE TRIGGER fail_physical_test BEFORE INSERT ON first_receipts FOR EACH ROW EXECUTE FUNCTION fail_physical_test()");
        try {
            app(RecordVisit::class)->record($collector, $r, $fields, UploadedFile::fake()->image('receipt.png'));
            $this->fail('Failure not injected');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Injected failure', $e->errorInfo[2]);
        }
        $this->assertSame($before, DB::table('custody_current')->get()->toJson());
        $this->assertDatabaseCount('commands', $commands);
        $this->assertDatabaseCount('receipt_visits', 0);
        $this->assertDatabaseCount('receipt_outcomes', 0);
        $this->assertDatabaseCount('first_receipts', 0);
        $this->assertDatabaseCount('route_consumptions', 0);
        $this->assertSame(['existing-private'], Storage::disk('directory_evidence')->allFiles());
    }

    public function test_dropoff_failed_remainder_can_be_reviewed_and_received_without_an_assignment(): void
    {
        [$admin,$owner,$collector,$place] = $this->allocationSetup();
        $staff = $this->pointStaff($admin, $place);
        $r = $this->readyRequest($admin, $owner, [], 'drop_off');
        $fields = $this->visitFields($r, ['absent']);
        $this->receiptSignIn($staff)->post(route('receipts.store', $r), $fields)->assertRedirect();
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $this->assertDatabaseCount('first_receipts', 0);
        $this->assertDatabaseCount('assignments', 0);
        $this->get(route('receipts.form', $r))->assertStatus(409);
        $this->ready($admin, $r);
        $fields = $this->visitFields($r);
        $this->post(route('receipts.store', $r), $fields)->assertRedirect(route('receipts.result', DB::table('receipt_visits')->orderByDesc('request_version')->value('id')));
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $this->assertDatabaseCount('receipt_outcomes', 2);
        $this->assertDatabaseCount('first_receipts', 1);
        $this->assertDatabaseCount('route_consumptions', 0);
    }

    public function test_private_evidence_retry_changed_payload_duplicate_codes_and_notice_permissions(): void
    {
        Storage::fake('directory_evidence');
        [$admin,$owner,$collector,$route,$ordered] = $this->pickupWork();
        $r = DB::table('assignments')->where('id', $ordered[0])->value('request_id');
        $fields = $this->visitFields($r);
        $this->receiptSignIn($collector)->postJson(route('receipts.store', $r), array_replace($fields, ['evidence' => [UploadedFile::fake()->image('a.png')]]))->assertUnprocessable()->assertJsonValidationErrors('evidence');
        $duplicate = $fields;
        $duplicate['items'][] = $duplicate['items'][0];
        $this->receiptSignIn($collector)->postJson(route('receipts.store', $r), $duplicate)->assertUnprocessable();
        $fields['evidence_kind'] = 'attachment';
        unset($fields['reference_code'],$fields['reference_issuer'],$fields['reference_attested']);
        $file = UploadedFile::fake()->image('private.png');
        $visit = app(RecordVisit::class)->record($collector, $r, $fields, $file);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $files = Storage::disk('directory_evidence')->allFiles();
        $events = DB::table('domain_events')->count();
        $outbox = DB::table('notification_outbox')->count();
        $this->assertSame($visit, app(RecordVisit::class)->record($collector, $r, $fields, $file));
        $this->assertSame($files, Storage::disk('directory_evidence')->allFiles());
        $this->assertDatabaseCount('domain_events', $events);
        $this->assertDatabaseCount('notification_outbox', $outbox);
        $this->get(route('receipts.evidence', $visit))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->receiptSignIn($owner)->get(route('receipts.evidence', $visit))->assertForbidden();
        $attachment = DB::table('receipt_visits')->where('id', $visit)->value('attachment_id');
        $subject = DB::table('first_receipts')->value('subject_id');
        $this->get(route('items.photo', [$subject, $attachment]))->assertNotFound();
        $this->get(route('items.show', $subject))->assertOk()->assertDontSee(route('items.photo', [$subject, $attachment]));
        $this->receiptSignIn($admin)->get(route('admin.evidence', $attachment))->assertNotFound();
        $changed = $fields;
        $changed['items'][0]['observation'] = 'Changed private claim';
        $this->receiptSignIn($collector)->postJson(route('receipts.store', $r), array_replace($changed, ['evidence' => $file]))->assertStatus(409);
        $this->assertDatabaseCount('first_receipts', 1);
        $this->assertDatabaseCount('receipt_discrepancies', 1);
        app(Notices::class)->deliver();
        $notice = DB::table('receipt_notifications')->first();
        $this->receiptSignIn($collector)->post(route('receipts.notices.read', $notice->id))->assertNotFound();
        $this->receiptSignIn($owner)->get(route('receipts.notices'))->assertOk()->assertSee('Physical visit recorded')->assertDontSee('Private synthetic');
        $this->post(route('receipts.notices.read', $notice->id))->assertRedirect();
        $this->assertNotNull(DB::table('receipt_notifications')->where('id', $notice->id)->value('read_at'));
    }

    public function test_point_scope_rejects_forged_consumers_other_points_and_stale_sessions(): void
    {
        [$admin,$owner,$collector,$place] = $this->allocationSetup();
        $fields = ['operation' => 'link', 'point_ref' => $place.':'.DB::table('places')->where('id', $place)->value('version'), 'expected_version' => 1, 'reason' => 'Synthetic scope', 'idempotency_key' => Str::uuid()->toString()];
        $this->receiptSignIn($admin)->post(route('admin.point-staff.save', $owner->id), $fields)->assertNotFound();
        $staff = $this->pointStaff($admin, $place);
        $r = $this->readyRequest($admin, $owner, [], 'drop_off');
        $this->receiptSignIn($collector)->get(route('receipts.form', $r))->assertNotFound();
        $this->receiptSignIn($staff)->get(route('receipts.form', $r))->assertOk();
        app(ManagePointStaff::class)->save($admin, $staff->id, ['operation' => 'deactivate', 'expected_version' => $staff->membership->version, 'reason' => 'Synthetic access revoked', 'idempotency_key' => Str::uuid()->toString()]);
        $this->get(route('receipts.form', $r))->assertRedirect(route('login'));
        $this->assertDatabaseCount('first_receipts', 0);
    }

    public function test_database_constraints_enforce_final_receipt_custody_immutability_and_claim_boundaries(): void
    {
        [$admin,$owner,$collector,$route,$ordered] = $this->pickupWork();
        $r = DB::table('assignments')->where('id', $ordered[0])->value('request_id');
        $row = DB::table('request_items')->where('request_id', $r)->first();
        $this->rejectsDatabase(function () use ($row) {
            DB::table('request_items')->where('id', $row->id)->update(['outcome' => 'collected', 'active' => false]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
        app(RecordVisit::class)->record($collector, $r, $this->visitFields($r));
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $f = DB::table('first_receipts')->first();
        $this->rejectsDatabase(fn () => DB::table('first_receipts')->insert((array) $f));
        $this->rejectsDatabase(fn () => DB::table('first_receipts')->where('subject_id', $f->subject_id)->delete());
        $this->rejectsDatabase(function () use ($f) {
            DB::table('custody_current')->where('subject_id', $f->subject_id)->update(['party_id' => $f->from_party_id]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
        $this->rejectsDatabase(fn () => DB::table('route_claims')->where('kind', 'vehicle')->delete());
        $this->assertSame($f->recipient_party_id, DB::table('custody_current')->where('subject_id', $f->subject_id)->value('party_id'));
    }

    public function test_recorded_safety_hold_cannot_be_cleared_by_a_ready_review_or_receipt_override(): void
    {
        [$admin,$owner,$collector,$place] = $this->allocationSetup();
        $staff = $this->pointStaff($admin, $place);
        $r = $this->readyRequest($admin, $owner, [], 'drop_off');
        app(RecordVisit::class)->record($staff, $r, $this->visitFields($r, ['safety_hold']));
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $this->receiptSignIn($admin)->postJson(route('admin.reviews.decide', $r), ['decision' => 'ready', 'expected_version' => DB::table('requests')->where('id', $r)->value('version'), 'idempotency_key' => Str::uuid()->toString(), 'reason' => 'Cannot override a physical hold'])->assertUnprocessable();
        $this->receiptSignIn($staff)->postJson(route('receipts.store', $r), $this->visitFields($r))->assertStatus(409);
        $this->assertDatabaseCount('first_receipts', 0);
        $this->assertTrue(DB::table('request_items')->where('request_id', $r)->value('active'));
    }

    public function test_unreceived_self_report_passes_deferred_boundary_and_direct_custody_or_receipt_state_requires_a_ledger(): void
    {
        $owner = User::factory()->create();
        $item = $this->device($owner);
        $before = DB::table('custody_current')->where('subject_id', $item->subject_id)->first();
        $fields = ['expected_version' => 1, 'idempotency_key' => Str::uuid()->toString(), 'data_status' => 'NO', 'physical_damage' => 'UNKNOWN', 'safety_concern' => 'NO', 'reason' => 'Synthetic unreceived revision', 'answers' => array_fill_keys(array_keys(Questions::tests('laptop', null)), 'UNKNOWN')];
        app(RegisterDevice::class)->revise($owner, $item, $fields);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $this->assertSame(2, DB::table('subjects')->where('id', $item->subject_id)->value('version'));
        $this->assertSame('NO', DB::table('items')->where('subject_id', $item->subject_id)->value('data_status'));
        $this->assertDatabaseCount('item_observations', 2);
        $this->assertDatabaseCount('first_receipts', 0);
        $this->assertEquals($before, DB::table('custody_current')->where('subject_id', $item->subject_id)->first());
        $this->rejectsDatabase(function () use ($item) {
            DB::table('items')->where('subject_id', $item->subject_id)->update(['processing_state' => 'received']);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
        $this->rejectsDatabase(function () use ($item) {
            DB::table('custody_current')->where('subject_id', $item->subject_id)->update(['version' => 2]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
    }

    private function rejectsDatabase(callable $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('Database protection did not reject the invalid mutation.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }
}
