<?php

namespace Tests\Feature;

use App\Actions\CustodyHandovers;
use App\Actions\ManagePointStaff;
use App\Actions\PlanRoutes;
use App\Actions\RecordVisit;
use App\Models\User;
use App\Support\Handovers\Context;
use App\Support\Handovers\Notices;
use App\Support\Routing\Execution;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\HandoverFixtures;
use Tests\TestCase;

class CustodyHandoverTest extends TestCase
{
    use DatabaseTransactions,HandoverFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1]);
    }

    public function test_completed_pickup_collector_can_transfer_via_point_to_facility_preserving_first_receipt_and_history(): void
    {
        $w = $this->handoverWork();
        $first = DB::table('first_receipts')->get()->toJson();
        $preserved = [];
        foreach (['requests', 'request_items', 'assignments', 'route_runs', 'routes', 'route_stops', 'route_approvals', 'route_claims', 'storage_assets'] as $table) {
            $preserved[$table] = DB::table($table)->get()->toJson();
        }
        $this->assertSame('completed', DB::table('assignments')->value('state'));
        $id = app(CustodyHandovers::class)->initiate($w['sender'], $this->initiateFields($w['sender'], $w['pointActor']));
        $this->handoverBoundary();
        $this->assertSame(2, DB::table('custody_current')->value('version'));
        app(CustodyHandovers::class)->change($w['pointActor'], $id, 'acknowledged', $this->acknowledgeFields($id));
        $this->handoverBoundary();
        $id2 = app(CustodyHandovers::class)->initiate($w['pointActor'], $this->initiateFields($w['pointActor'], $w['receiver']));
        app(CustodyHandovers::class)->change($w['receiver'], $id2, 'acknowledged', $this->acknowledgeFields($id2));
        $this->handoverBoundary();
        $this->assertSame(4, DB::table('custody_current')->value('version'));
        $this->assertSame($first, DB::table('first_receipts')->get()->toJson());
        foreach ($preserved as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->get()->toJson(), $table);
        }
        $this->assertSame($w['owner']->id, DB::table('items')->value('owner_user_id'));
        $this->assertSame('YES', DB::table('items')->value('data_status'));
        $this->assertSame($w['facility'], DB::table('parties')->where('id', DB::table('custody_current')->value('party_id'))->value('place_id'));
        $this->assertDatabaseCount('first_receipts', 1);
        $this->assertDatabaseCount('handover_receipts', 2);
        $this->assertTrue(app(Execution::class)->current(DB::table('route_runs')->value('id')));
        $this->receiptSignIn($w['receiver'])->get(route('handovers.index'))->assertOk()->assertDontSee('Prepare handover to');
        $this->assertCount(0, app(Context::class)->held($w['receiver']));
    }

    public function test_dropoff_point_can_initiate_facility_receipt_without_collector_assignment(): void
    {
        $w = $this->handoverWork(1, true);
        $this->assertDatabaseCount('assignments', 0);
        $this->receiptSignIn($w['sender'])->get(route('handovers.index'))->assertOk()->assertSee('Items currently held');
        $this->get(route('handovers.create', $w['receiver']->id))->assertOk()->assertSee('Initiate pending handover');
        $id = $this->initiateHandover($w);
        $this->receiptSignIn($w['receiver'])->get(route('handovers.show', $id))->assertOk()->assertSee('Record recipient outcomes')->assertSee('Scan with camera');
        $this->post(route('handovers.change', [$id, 'acknowledged']), $this->acknowledgeFields($id))->assertRedirect(route('handovers.show', $id));
        $this->handoverBoundary();
        $this->get(route('handovers.show', $id))->assertOk()->assertSee('Acknowledged facility receipt')->assertSee('does not start processing');
        $this->assertSame(3, DB::table('custody_current')->value('version'));
    }

    public function test_partial_acceptance_disputes_and_omissions_retain_claims_and_sender_custody(): void
    {
        $w = $this->handoverWork(6);
        $id = $this->initiateHandover($w);
        $fields = $this->acknowledgeFields($id, ['accepted', 'missing', 'damaged', 'mismatch', 'refused', 'pending']);
        app(CustodyHandovers::class)->change($w['receiver'], $id, 'acknowledged', $fields);
        $this->handoverBoundary();
        $this->assertSame('disputed', DB::table('handovers')->where('id', $id)->value('state'));
        $this->assertSame(4, DB::table('handover_items')->where('state', 'disputed')->count());
        $this->assertSame(5, DB::table('handover_items')->where('active', true)->count());
        $this->assertSame(1, DB::table('custody_current')->where('version', 3)->count());
        $this->assertSame(5, DB::table('custody_current')->where('version', 2)->count());
        $this->assertDatabaseCount('handover_receipts', 1);
        $this->assertSame(0, DB::table('request_items')->where('active', true)->count());
    }

    public function test_withdrawal_and_evidence_backed_resolution_never_transfer_or_erase_history_and_allow_fresh_initiation(): void
    {
        $w = $this->handoverWork(3);
        $id = $this->initiateHandover($w);
        app(CustodyHandovers::class)->change($w['receiver'], $id, 'acknowledged', $this->acknowledgeFields($id, ['missing', 'mismatch', 'pending']));
        $this->handoverBoundary();
        app(CustodyHandovers::class)->change($w['sender'], $id, 'withdrawn', $this->acknowledgeFields($id, ['pending', 'pending', 'withdrawn']));
        app(CustodyHandovers::class)->change($w['admin'], $id, 'resolved', $this->acknowledgeFields($id, ['review_continues', 'closed_no_transfer', 'pending']));
        $this->handoverBoundary();
        $this->assertSame(1, DB::table('handover_items')->where('active', true)->count());
        $this->assertSame(3, DB::table('custody_current')->where('version', 2)->count());
        $this->assertDatabaseCount('handover_receipts', 0);
        $this->assertSame(2, DB::table('handover_outcomes')->whereIn('outcome', ['missing', 'mismatch'])->count());
        $new = $this->initiateHandover($w);
        $this->assertNotSame($id, $new);
        $this->assertSame(2, DB::table('handover_items')->where('handover_id', $new)->count());
        app(CustodyHandovers::class)->change($w['receiver'], $new, 'acknowledged', $this->acknowledgeFields($new));
        $this->handoverBoundary();
        $this->assertSame(2, DB::table('custody_current')->where('version', 3)->count());
    }

    public function test_identical_retries_return_original_results_but_changed_payload_and_new_competing_initiation_conflict(): void
    {
        $w = $this->handoverWork();
        $f = $this->initiateFields($w['sender'], $w['receiver']);
        $id = app(CustodyHandovers::class)->initiate($w['sender'], $f);
        $this->assertSame($id, app(CustodyHandovers::class)->initiate($w['sender'], $f));
        $this->rejected(fn () => app(CustodyHandovers::class)->initiate($w['sender'], array_replace($f, ['reason' => 'Different private input'])), 409);
        $this->rejected(fn () => app(CustodyHandovers::class)->initiate($w['sender'], array_replace($f, ['idempotency_key' => Str::uuid()->toString()])), 409);
        $ack = $this->acknowledgeFields($id);
        app(CustodyHandovers::class)->change($w['receiver'], $id, 'acknowledged', $ack);
        $this->assertSame($id, app(CustodyHandovers::class)->change($w['receiver'], $id, 'acknowledged', $ack));
        $this->assertSame($id, app(CustodyHandovers::class)->initiate($w['sender'], $f));
        $this->handoverBoundary();
        $this->assertDatabaseCount('handovers', 1);
        $this->assertDatabaseCount('handover_operations', 2);
        $this->assertDatabaseCount('handover_receipts', 1);
        $this->assertDatabaseCount('handover_notifications', 0);
    }

    public function test_scope_loss_removes_old_links_and_retries_but_administrator_can_withdraw_pending_records(): void
    {
        $w = $this->handoverWork();
        $id = $this->initiateHandover($w);
        $f = $this->acknowledgeFields($id);
        app(ManagePointStaff::class)->save($w['admin'], $w['receiver']->id, ['operation' => 'deactivate', 'expected_version' => $w['receiver']->membership->version, 'reason' => 'Private scope revocation', 'idempotency_key' => Str::uuid()->toString()]);
        $this->rejected(fn () => app(CustodyHandovers::class)->change($w['receiver'], $id, 'acknowledged', $f), 403);
        $this->receiptSignIn($w['receiver'])->get(route('handovers.show', $id))->assertRedirect(route('login'));
        $this->receiptSignIn($w['sender'])->get(route('handovers.show', $id))->assertOk()->assertSee('Withdraw pending items');
        app(CustodyHandovers::class)->change($w['admin'], $id, 'withdrawn', $this->acknowledgeFields($id, ['withdrawn']));
        $this->handoverBoundary();
        $this->assertSame('withdrawn', DB::table('handover_items')->where('handover_id', $id)->value('state'));
        $this->assertSame(2, DB::table('custody_current')->value('version'));
    }

    public function test_admin_and_other_staff_cannot_acknowledge_and_owners_see_only_owned_safe_milestones(): void
    {
        $w = $this->handoverWork();
        $id = $this->initiateHandover($w);
        $f = $this->acknowledgeFields($id);
        $this->rejected(fn () => app(CustodyHandovers::class)->change($w['admin'], $id, 'acknowledged', $f), 404);
        $other = $this->pointStaff($w['admin'], $w['facility']);
        $this->rejected(fn () => app(CustodyHandovers::class)->change($other, $id, 'acknowledged', $f), 404);
        $this->receiptSignIn($other)->get(route('handovers.show', $id))->assertNotFound();
        $this->receiptSignIn($w['owner'])->get(route('handovers.show', $id))->assertOk()->assertDontSee('Private synthetic')->assertDontSee('PRIVATE-SYNTHETIC')->assertDontSee('Download private evidence')->assertDontSee('Record recipient outcomes');
        $this->get(route('items.show', $w['ids'][0]))->assertOk()->assertSee('View your handover milestone');
        $stranger = User::factory()->create();
        $this->receiptSignIn($stranger)->get(route('handovers.show', $id))->assertNotFound();
    }

    public function test_scoped_lookup_and_safe_validation_feedback_do_not_flash_private_inputs(): void
    {
        $w = $this->handoverWork();
        $id = $this->initiateHandover($w);
        $this->receiptSignIn($w['receiver']);
        $this->post(route('handovers.lookup', $id), ['code' => 'EWC-'.str_repeat('A', 32)])->assertRedirect(route('handovers.show', $id))->assertSessionHasErrors('code');
        $f = $this->acknowledgeFields($id);
        $f['items'][0]['outcome'] = 'invented';
        $this->post(route('handovers.change', [$id, 'acknowledged']), $f)->assertSessionHasErrors('items.0.outcome')->assertSessionMissing('_old_input.items')->assertSessionMissing('_old_input.reference_code');
        $this->withCookie(config('session.cookie'), session()->getId())->get(route('handovers.show', $id))->assertOk()->assertSee('data-error-summary', false)->assertSee('aria-invalid="true"', false)->assertSee('outcome-error-0', false);
        $code = DB::table('subjects')->where('id', $w['ids'][0])->value('public_code');
        $this->post(route('handovers.lookup', $id), ['code' => $code])->assertSessionHas('selected_item');
        $this->assertDatabaseCount('handover_receipts', 0);
        $this->assertDatabaseCount('handover_operations', 1);
    }

    public function test_private_evidence_access_rollback_cleanup_and_deduplicated_notices_leave_unrelated_outbox_untouched(): void
    {
        Storage::fake('directory_evidence');
        $w = $this->handoverWork();
        $unrelated = DB::table('notification_outbox')->pluck('id');
        $f = $this->initiateFields($w['sender'], $w['receiver']);
        unset($f['reference_issuer'], $f['reference_code'], $f['reference_attested']);
        $f['evidence_kind'] = 'attachment';
        $file = UploadedFile::fake()->image('private.png', 20, 20);
        $id = app(CustodyHandovers::class)->initiate($w['sender'], $f, $file);
        $op = DB::table('handover_operations')->where('handover_id', $id)->first();
        $this->receiptSignIn($w['receiver'])->get(route('handovers.evidence', $op->id))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->receiptSignIn($w['owner'])->get(route('handovers.evidence', $op->id))->assertForbidden();
        $this->assertSame(3, app(Notices::class)->deliver());
        $this->assertSame(0, app(Notices::class)->deliver());
        $this->assertSame($unrelated->count(), DB::table('notification_outbox')->whereIn('id', $unrelated)->where('state', 'pending')->count());
        $notice = DB::table('handover_notifications')->where('recipient_id', $w['owner']->id)->value('id');
        $this->post(route('handovers.notices.read', $notice))->assertRedirect(route('handovers.notices'));
        $this->receiptSignIn($w['sender'])->post(route('handovers.notices.read', $notice))->assertNotFound();
        $count = count(Storage::disk('directory_evidence')->allFiles());
        DB::unprepared("CREATE FUNCTION fail_handover_outcome() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Injected rollback'; END; $$; CREATE TRIGGER injected_failure BEFORE INSERT ON handover_outcomes FOR EACH ROW EXECUTE FUNCTION fail_handover_outcome()");
        $ack = $this->acknowledgeFields($id);
        unset($ack['reference_issuer'], $ack['reference_code'], $ack['reference_attested']);
        $ack['evidence_kind'] = 'attachment';
        $this->databaseRejected(fn () => app(CustodyHandovers::class)->change($w['receiver'], $id, 'acknowledged', $ack, UploadedFile::fake()->image('another.png')));
        $this->assertSame($count, count(Storage::disk('directory_evidence')->allFiles()));
        $this->assertDatabaseCount('handover_operations', 1);
        $this->assertDatabaseCount('handover_receipts', 0);
    }

    public function test_database_rejects_fabricated_custody_projection_missing_receipt_and_history_edits(): void
    {
        $w = $this->handoverWork();
        $id = $this->initiateHandover($w);
        $hi = DB::table('handover_items')->where('handover_id', $id)->first();
        $h = DB::table('handovers')->where('id', $id)->first();
        $this->databaseRejected(fn () => DB::table('custody_current')->where('subject_id', $hi->subject_id)->update(['party_id' => $h->receiver_party_id, 'version' => 3]));
        $this->databaseRejected(fn () => DB::table('subjects')->where('id', $hi->subject_id)->increment('version'));
        $this->databaseRejected(fn () => DB::table('handover_items')->where('id', $hi->id)->update(['state' => 'accepted', 'active' => false]));
        $this->databaseRejected(fn () => DB::table('handover_operations')->where('handover_id', $id)->delete());
        $this->databaseRejected(fn () => DB::table('first_receipts')->where('subject_id', $hi->subject_id)->update(['custody_version' => 99]));
        app(CustodyHandovers::class)->change($w['receiver'], $id, 'acknowledged', $this->acknowledgeFields($id));
        $this->handoverBoundary();
        $receipt = (array) DB::table('handover_receipts')->first();
        $receipt['custody_version'] += 2;
        $this->databaseRejected(fn () => DB::table('handover_receipts')->insert($receipt));
        $this->assertDatabaseCount('handover_receipts', 1);
    }

    public function test_destination_capability_and_version_changes_block_acknowledgment_with_recovery(): void
    {
        $w = $this->handoverWork();
        $id = $this->initiateHandover($w);
        DB::table('place_categories')->where('place_id', $w['facility'])->delete();
        $this->receiptSignIn($w['receiver'])->post(route('handovers.change', [$id, 'acknowledged']), $this->acknowledgeFields($id))->assertSessionHasErrors('items');
        $this->assertSame(2, DB::table('custody_current')->value('version'));
        app(CustodyHandovers::class)->change($w['sender'], $id, 'withdrawn', $this->acknowledgeFields($id, ['withdrawn']));
        $this->handoverBoundary();
    }

    public function test_forged_fields_and_incompatible_destinations_fail_without_partial_records(): void
    {
        $w = $this->handoverWork();
        $f = $this->initiateFields($w['sender'], $w['receiver']);
        $this->receiptSignIn($w['sender']);
        foreach (['sender_party_id' => null, 'custody' => [], 'owner_user_id' => $w['owner']->id] as $key => $value) {
            $this->post(route('handovers.store'), $f + [$key => $value])->assertSessionHasErrors('submission');
        }
        DB::table('place_categories')->where('place_id', $w['facility'])->delete();
        $this->post(route('handovers.store'), $f)->assertSessionHasErrors('subjects');
        $this->assertDatabaseCount('handovers', 0);
        $this->assertDatabaseCount('handover_operations', 0);
        $this->assertDatabaseCount('handover_items', 0);
        $this->assertSame(2, DB::table('custody_current')->value('version'));
    }

    public function test_database_active_claim_index_rejects_direct_competing_manifests(): void
    {
        $w = $this->handoverWork();
        $id = $this->initiateHandover($w);
        $h = (array) DB::table('handovers')->where('id', $id)->first();
        $hi = (array) DB::table('handover_items')->where('handover_id', $id)->first();
        $h['id'] = Str::uuid()->toString();
        $hi['id'] = Str::uuid()->toString();
        $hi['handover_id'] = $h['id'];
        try {
            DB::transaction(function () use ($h, $hi) {
                DB::table('handovers')->insert($h);
                DB::table('handover_items')->insert($hi);
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
            $this->fail('Expected direct active-claim enforcement');
        } catch (QueryException $e) {
            $this->assertSame('23505', $e->getCode());
            $this->assertStringContainsString('handover_subject_active_unique', $e->errorInfo[2]);
        }
        $this->assertDatabaseCount('handovers', 1);
        $this->handoverBoundary();
    }

    public function test_consumer_history_and_notices_filter_other_owners_items_and_operations(): void
    {
        $w = $this->handoverWork(1, true);
        $other = User::factory()->create();
        $request = $this->readyRequest($w['admin'], $other, [], 'drop_off');
        app(RecordVisit::class)->record($w['sender'], $request, $this->visitFields($request));
        $this->handoverBoundary();
        $id = $this->initiateHandover($w);
        $f = $this->acknowledgeFields($id);
        $foreign = app(Context::class)->rows($id)->firstWhere('owner_user_id', $other->id);
        $f['items'] = array_values(array_filter($f['items'], fn ($row) => $row['id'] === $foreign->id));
        app(CustodyHandovers::class)->change($w['receiver'], $id, 'acknowledged', $f);
        $this->handoverBoundary();
        $this->receiptSignIn($w['owner'])->get(route('handovers.show', $id))->assertOk()->assertDontSee($foreign->public_code)->assertDontSee('Acknowledged · v2');
        app(Notices::class)->deliver();
        $this->assertSame(1, DB::table('handover_notifications')->where('recipient_id', $w['owner']->id)->count());
        $this->assertSame(2, DB::table('handover_notifications')->where('recipient_id', $other->id)->count());
    }

    public function test_later_handover_at_a_consumed_stop_preserves_execution_of_the_next_pickup(): void
    {
        [$admin,$owner,$collector,$point,$depot,$vehicle] = $this->routingSetup();
        $receiver = $this->pointStaff($admin, $this->facility($point));
        $assignments = [$this->accepted($admin, $owner, $collector, $point), $this->accepted($admin, $owner, $collector, $point)];
        $run = app(PlanRoutes::class)->create($admin, $this->routeFields($depot, $vehicle, $assignments));
        app(PlanRoutes::class)->approve($admin, $run, $this->approvalFields());
        $route = DB::table('routes')->where('run_id', $run)->where('method', 'proposed')->value('id');
        $ordered = DB::table('route_stops')->where('route_id', $route)->orderBy('position')->pluck('assignment_id')->all();
        $first = DB::table('assignments')->where('id', $ordered[0])->value('request_id');
        app(RecordVisit::class)->record($collector, $first, $this->visitFields($first));
        $this->handoverBoundary();
        $id = app(CustodyHandovers::class)->initiate($collector, $this->initiateFields($collector, $receiver));
        app(CustodyHandovers::class)->change($receiver, $id, 'acknowledged', $this->acknowledgeFields($id));
        $this->handoverBoundary();
        $this->assertTrue(app(Execution::class)->current($run));
        $next = DB::table('assignments')->where('id', $ordered[1])->value('request_id');
        app(RecordVisit::class)->record($collector, $next, $this->visitFields($next));
        $this->handoverBoundary();
        $this->assertDatabaseCount('first_receipts', 2);
        $this->assertDatabaseCount('handover_receipts', 1);
        $this->assertSame('approved', DB::table('route_approvals')->where('run_id', $run)->value('state'));
    }

    public function test_accepted_facility_milestone_is_historical_even_if_place_classification_later_changes(): void
    {
        $w = $this->handoverWork();
        $id = $this->initiateHandover($w);
        app(CustodyHandovers::class)->change($w['receiver'], $id, 'acknowledged', $this->acknowledgeFields($id));
        $this->handoverBoundary();
        DB::table('places')->where('id', $w['facility'])->update(['kind' => 'collection_point', 'version' => DB::raw('version+1')]);
        $this->receiptSignIn($w['owner'])->get(route('handovers.show', $id))->assertOk()->assertSee('Acknowledged facility receipt');
        $this->assertDatabaseCount('first_receipts', 1);
    }

    public function test_current_custody_cannot_be_deleted_or_reassigned_to_another_subject(): void
    {
        $w = $this->handoverWork(2);
        $this->databaseRejected(fn () => DB::table('custody_current')->where('subject_id', $w['ids'][0])->delete());
        $this->databaseRejected(fn () => DB::table('custody_current')->where('subject_id', $w['ids'][0])->update(['subject_id' => Str::uuid()->toString()]));
        $this->assertSame(2, DB::table('custody_current')->count());
    }

    private function rejected(callable $action, int $status): void
    {
        try {
            $action();
            $this->fail('Expected authorization/version conflict');
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }

    private function databaseRejected(callable $action): void
    {
        try {
            DB::transaction(function () use ($action) {
                $action();
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
            $this->fail('Expected enforced database boundary');
        } catch (QueryException $e) {
            $this->assertContains($e->getCode(), ['P0001', '23505', '23514']);
        }
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }
}
