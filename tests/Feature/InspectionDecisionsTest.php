<?php

namespace Tests\Feature;

use App\Actions\CustodyHandovers;
use App\Actions\InspectItems;
use App\Actions\ManagePointStaff;
use App\Enums\Role;
use App\Models\User;
use App\Support\Assessments\Notices;
use App\Support\Collection\AdminMutation;
use App\Support\Routing\Execution;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InspectionFixtures;
use Tests\TestCase;

class InspectionDecisionsTest extends TestCase
{
    use DatabaseTransactions, InspectionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1]);
    }

    public static function supportedJourneys(): array
    {
        return [['laptop', null, 'laptop-healthy'], ['standalone_part', 'hdd', 'hdd-healthy'], ['standalone_part', 'ssd', 'ssd-healthy'], ['standalone_part', 'ict_cooling_fan', 'ict_cooling_fan-healthy']];
    }

    #[DataProvider('supportedJourneys')]
    public function test_supported_http_journey_freezes_observations_then_requires_separate_decision(string $category, ?string $subtype, string $case): void
    {
        $w = $this->inspectionWork($category, $subtype);
        $subject = $w['ids'][0];
        $before = $this->preserved();
        $fields = $this->inspectionFields($subject, $case);
        $this->receiptSignIn($w['receiver'])->get(route('assessments.index'))->assertOk()->assertSee('Open inspection and decisions');
        $this->get(route('assessments.show', ['subject' => $subject, 'action' => 'inspect']))->assertOk()->assertSee('Freeze inspection and recommendation');
        $this->post(route('assessments.store', $subject), $fields)->assertRedirect(route('assessments.show', $subject))->assertSessionHas('status');
        $this->handoverBoundary();
        $a = DB::table('assessments')->where('subject_id', $subject)->first();
        $this->assertSame('refurbish', $a->recommendation);
        $this->assertSame($w['receiver']->id, $a->actor_id);
        $this->assertNotEmpty($a->rule_checksum);
        $this->assertSame($before, $this->preserved());
        $this->assertNull(DB::table('assessment_current')->where('subject_id', $subject)->value('decision_id'));
        $this->assertDatabaseCount('decisions', 0);
        $this->get(route('assessments.show', $subject))->assertOk()->assertSee('First matching rule R07')->assertSee('Decisive answers:')->assertSee('Workflow version:')->assertSee('Awaiting a separate decision');
        $this->post(route('assessments.decide', $subject), $this->decisionFields($subject))->assertRedirect(route('assessments.show', $subject));
        $this->handoverBoundary();
        $this->assertSame('assessed', DB::table('items')->where('subject_id', $subject)->value('processing_state'));
        $this->assertSame('refurbish', DB::table('decisions')->value('pathway'));
        $this->assertSame($before, $this->preserved());
        $body = $this->receiptSignIn($w['owner'])->get(route('assessments.show', $subject))->assertOk()->assertSee('Human pathway decision recorded')->assertDontSee($fields['reason'])->assertDontSee($fields['reference_code'])->assertDontSee($w['receiver']->display_name)->getContent();
        $this->assertStringNotContainsString($a->rule_checksum, $body);
        if (in_array($subtype, ['hdd', 'ssd'], true)) {
            $this->assertSame('YES', $a->data_status);
            $this->assertSame('YES', DB::table('items')->where('subject_id', $subject)->value('data_status'));
            $this->assertDatabaseHas('storage_assets', ['subject_id' => $subject, 'media_kind' => $subtype]);
        }
    }

    public function test_current_facility_colleague_can_inspect_and_decide_without_being_named_original_receiver(): void
    {
        $w = $this->inspectionWork('laptop');
        $colleague = $this->pointStaff($w['admin'], $w['facility']);
        $id = app(InspectItems::class)->inspect($colleague, $w['ids'][0], $this->inspectionFields($w['ids'][0], 'laptop-healthy'));
        $d = app(InspectItems::class)->decide($colleague, $w['ids'][0], $this->decisionFields($w['ids'][0], 'recycle'));
        $this->handoverBoundary();
        $this->assertSame($colleague->id, DB::table('assessments')->where('id', $id)->value('actor_id'));
        $this->assertTrue(DB::table('decisions')->where('id', $d)->value('override'));
        $this->assertSame('refurbish', DB::table('assessments')->where('id', $id)->value('recommendation'));
    }

    public function test_reassessment_supersedes_only_current_decision_and_historical_retries_do_not_restore_it(): void
    {
        $w = $this->inspectionWork();
        $s = $w['ids'][0];
        $action = app(InspectItems::class);
        $f1 = $this->inspectionFields($s);
        $a1 = $action->inspect($w['receiver'], $s, $f1);
        $d1f = $this->decisionFields($s);
        $d1 = $action->decide($w['receiver'], $s, $d1f);
        $f2 = $this->inspectionFields($s, 'ssd-unknown_decisive');
        $a2 = $action->inspect($w['receiver'], $s, $f2);
        $this->handoverBoundary();
        $this->assertSame($a1, $action->inspect($w['receiver'], $s, $f1));
        $this->assertSame($d1, $action->decide($w['receiver'], $s, $d1f));
        $this->assertNull(DB::table('assessment_current')->where('subject_id', $s)->value('decision_id'));
        $this->assertSame($a2, DB::table('assessment_current')->where('subject_id', $s)->value('assessment_id'));
        $this->receiptSignIn($w['receiver'])->postJson(route('assessments.decide', $s), array_replace($this->decisionFields($s), ['assessment_id' => $a1]))->assertConflict();
        $this->postJson(route('assessments.store', $s), array_replace($f1, ['reason' => 'Different private payload']))->assertConflict();
        $d2 = $action->decide($w['receiver'], $s, $this->decisionFields($s, 'recycle'));
        $this->handoverBoundary();
        $this->assertSame($a2, DB::table('decisions')->where('id', $d2)->value('assessment_id'));
        $this->assertDatabaseCount('decisions', 2);
        $this->assertDatabaseCount('assessments', 2);
    }

    public function test_explicit_unknown_is_complete_but_missing_and_inappropriate_na_are_validation_errors(): void
    {
        $w = $this->inspectionWork('peripheral', 'keyboard');
        $s = $w['ids'][0];
        $f = $this->inspectionFields($s, 'keyboard-unknown_decisive');
        $bad = $f;
        unset($bad['answers']['input']);
        $this->receiptSignIn($w['receiver'])->postJson(route('assessments.store', $s), $bad)->assertUnprocessable()->assertJsonValidationErrors('answers.input');
        $bad = $f;
        $bad['answers']['input'] = 'NOT_APPLICABLE';
        $this->postJson(route('assessments.store', $s), $bad)->assertUnprocessable()->assertJsonValidationErrors('answers.input');
        $bad = $f;
        $bad['na_reasons'] = [];
        $this->postJson(route('assessments.store', $s), $bad)->assertUnprocessable()->assertJsonValidationErrors('na_reasons.power');
        app(InspectItems::class)->inspect($w['receiver'], $s, $f);
        $this->handoverBoundary();
        $this->assertSame('hold', DB::table('assessments')->value('recommendation'));
        $this->assertDatabaseHas('assessment_answers', ['question_code' => 'input', 'value' => 'UNKNOWN']);
    }

    public function test_safety_and_part_scope_holds_cannot_be_overridden_or_silently_cleared_by_reassessment(): void
    {
        $w = $this->inspectionWork('standalone_part', 'ict_cooling_fan');
        $s = $w['ids'][0];
        $f = $this->inspectionFields($s, 'ict_cooling_fan-healthy');
        $f['answers']['fan_electronics'] = 'UNKNOWN';
        app(InspectItems::class)->inspect($w['receiver'], $s, $f);
        $this->receiptSignIn($w['receiver'])->postJson(route('assessments.decide', $s), $this->decisionFields($s))->assertUnprocessable()->assertJsonValidationErrors('pathway');
        app(InspectItems::class)->decide($w['receiver'], $s, $this->decisionFields($s, 'hold'));
        app(InspectItems::class)->inspect($w['receiver'], $s, $this->inspectionFields($s, 'ict_cooling_fan-healthy'));
        $this->postJson(route('assessments.decide', $s), $this->decisionFields($s, 'recycle'))->assertUnprocessable();
        $this->handoverBoundary();
        $this->assertDatabaseCount('decisions', 1);
        $this->get(route('assessments.show', $s))->assertOk()->assertSee('Earlier holds are not silently cleared.');
    }

    public function test_scope_role_cross_facility_owner_and_evidence_boundaries(): void
    {
        Storage::fake('directory_evidence');
        $w = $this->inspectionWork('laptop');
        $s = $w['ids'][0];
        $f = $this->inspectionFields($s, 'laptop-healthy');
        $f['evidence_kind'] = 'attachment';
        unset($f['reference_issuer'],$f['reference_code'],$f['reference_attested']);
        $id = app(InspectItems::class)->inspect($w['receiver'], $s, $f, UploadedFile::fake()->image('private.png'));
        $this->handoverBoundary();
        $other = $this->pointStaff($w['admin'], $this->facility($w['point']));
        foreach ([$w['owner'], $w['collector'], $w['pointActor'], $w['admin'], $other, User::factory()->create()] as $actor) {
            $this->receiptSignIn($actor)->get(route('assessments.evidence', $id))->assertStatus($actor->role() === Role::Processor ? 404 : 403);
            if ($actor->id !== $w['owner']->id) {
                $this->get(route('assessments.show', $s))->assertStatus(in_array($actor->role(), [Role::Processor, Role::Consumer], true) ? 404 : 403);
            }
        }
        $this->receiptSignIn($w['receiver'])->get(route('assessments.evidence', $id))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        app(ManagePointStaff::class)->save($w['admin'], $w['receiver']->id, ['operation' => 'deactivate', 'expected_version' => $w['receiver']->membership->version, 'reason' => 'Synthetic access revocation', 'idempotency_key' => Str::uuid()->toString()]);
        $this->get(route('assessments.evidence', $id))->assertRedirect(route('login'));
        $this->assertDatabaseCount('assessments', 1);
    }

    public function test_actionable_capability_and_pending_transfer_blockers_do_not_create_partial_records(): void
    {
        $w = $this->handoverWork();
        $s = $w['ids'][0];
        $this->initiateHandover($w);
        $this->receiptSignIn($w['receiver'])->get(route('assessments.show', $s))->assertNotFound();
        $this->assertDatabaseCount('assessments', 0);
        // A pending receiving manifest grants no facility custody or inspection authority.
        $id = DB::table('handovers')->value('id');
        app(CustodyHandovers::class)->change($w['receiver'], $id, 'acknowledged', $this->acknowledgeFields($id));
        DB::table('place_categories')->where('place_id', $w['facility'])->where('subtype', 'ssd')->delete();
        $before = $this->preserved();
        $this->receiptSignIn($w['receiver'])->get(route('assessments.show', $s))->assertOk()->assertSee('lacks the exact category/subtype capability');
        $this->postJson(route('assessments.store', $s), $this->inspectionFields($s))->assertUnprocessable()->assertJsonValidationErrors('submission');
        $this->assertSame($before, $this->preserved());
        $this->assertDatabaseCount('assessments', 0);
    }

    public function test_private_flash_policy_field_associations_and_safe_rejection_of_forged_values(): void
    {
        $w = $this->inspectionWork();
        $s = $w['ids'][0];
        $f = $this->inspectionFields($s);
        unset($f['answers']['detection']);
        $this->receiptSignIn($w['receiver'])->post(route('assessments.store', $s), $f)->assertRedirect(route('assessments.show', ['subject' => $s, 'action' => 'inspect']))->assertSessionHasErrors('answers.detection')->assertSessionMissing('_old_input.reason')->assertSessionMissing('_old_input.reference_code')->assertSessionHas('_old_input.answers.diagnostics', 'PASS');
        $this->withCookie(config('session.cookie'), session()->getId())->get(route('assessments.show', ['subject' => $s, 'action' => 'inspect']))->assertOk()->assertSee('data-error-summary', false)->assertSee('href="#answer-detection"', false)->assertSee('aria-invalid="true"', false)->assertSee('aria-describedby="answer-detection-error"', false)->assertDontSee($f['reference_code']);
        foreach (['owner_user_id', 'data_status', 'parent_item_id', 'storage_assets', 'recommendation', 'rule_set_id', 'custody_version'] as $field) {
            foreach ([null, []] as $value) {
                $this->receiptSignIn($w['receiver'])->postJson(route('assessments.store', $s), $this->inspectionFields($s) + [$field => $value])->assertUnprocessable();
            }
        }
        $this->assertDatabaseCount('assessments', 0);
    }

    public function test_direct_mutations_fail_when_deferred_constraints_are_forced(): void
    {
        $w = $this->inspectionWork();
        $s = $w['ids'][0];
        $before = $this->preserved();
        $this->rejectDatabase(fn () => DB::table('items')->where('subject_id', $s)->update(['processing_state' => 'assessed']));
        $this->rejectDatabase(fn () => DB::table('subjects')->where('id', $s)->increment('version'));
        $this->rejectDatabase(fn () => DB::table('rule_sets')->update(['checksum' => 'tampered']));
        $id = app(InspectItems::class)->inspect($w['receiver'], $s, $this->inspectionFields($s));
        $this->handoverBoundary();
        $this->rejectDatabase(fn () => DB::table('assessments')->where('id', $id)->update(['recommendation' => 'recycle']));
        $this->rejectDatabase(fn () => DB::table('assessment_answers')->where('assessment_id', $id)->delete());
        $this->rejectDatabase(fn () => DB::table('assessment_current')->where('subject_id', $s)->update(['version' => 2]));
        $this->rejectDatabase(fn () => DB::table('assessment_current')->where('subject_id', $s)->delete());
        $this->rejectDatabase(fn () => DB::table('items')->where('subject_id', $s)->update(['processing_state' => 'released']));
        $this->assertSame($before, $this->preserved());
    }

    public function test_rollback_removes_only_new_private_file_and_all_partial_operations(): void
    {
        Storage::fake('directory_evidence');
        Storage::disk('directory_evidence')->put('retained.txt', 'synthetic retained evidence');
        $w = $this->inspectionWork();
        $s = $w['ids'][0];
        $before = $this->preserved();
        DB::unprepared("CREATE FUNCTION fail_inspection_fixture() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Synthetic post-file failure'; END; $$; CREATE TRIGGER fail_inspection_fixture BEFORE INSERT ON assessment_answers FOR EACH ROW EXECUTE FUNCTION fail_inspection_fixture();");
        $f = $this->inspectionFields($s);
        $f['evidence_kind'] = 'attachment';
        unset($f['reference_issuer'],$f['reference_code'],$f['reference_attested']);
        try {
            app(InspectItems::class)->inspect($w['receiver'], $s, $f, UploadedFile::fake()->image('private.png'));
            $this->fail('Expected injected failure');
        } catch (QueryException) {
        }
        $this->assertSame(['retained.txt'], Storage::disk('directory_evidence')->allFiles());
        $this->assertDatabaseCount('assessments', 0);
        $this->assertDatabaseCount('assessment_answers', 0);
        $this->assertDatabaseCount('assessment_current', 0);
        $this->assertSame($before, $this->preserved());
    }

    public function test_supported_notices_are_private_deduplicated_and_leave_historical_outbox_untouched(): void
    {
        $w = $this->inspectionWork();
        $s = $w['ids'][0];
        $legacy = DB::table('notification_outbox')->get()->toJson();
        app(InspectItems::class)->inspect($w['receiver'], $s, $this->inspectionFields($s));
        app(InspectItems::class)->decide($w['receiver'], $s, $this->decisionFields($s));
        $this->handoverBoundary();
        $this->assertSame(4, app(Notices::class)->deliver());
        $this->assertSame(0, app(Notices::class)->deliver());
        $legacyIds = json_decode($legacy);
        $this->assertSame($legacy, DB::table('notification_outbox')->whereIn('id', array_column($legacyIds, 'id'))->get()->toJson());
        $notice = DB::table('assessment_notifications')->where('recipient_id', $w['owner']->id)->value('id');
        $this->receiptSignIn($w['owner'])->get(route('assessments.notices'))->assertOk()->assertDontSee('PRIVATE-SYNTHETIC-01');
        $this->post(route('assessments.notices.read', $notice))->assertRedirect();
        $this->receiptSignIn($w['collector'])->post(route('assessments.notices.read', $notice))->assertNotFound();
    }

    public function test_assessment_and_decision_preserve_approved_consumed_route_execution(): void
    {
        $w = $this->inspectionWork();
        $s = $w['ids'][0];
        $run = DB::table('route_runs')->value('id');
        $this->assertTrue(app(Execution::class)->current($run));
        app(InspectItems::class)->inspect($w['receiver'], $s, $this->inspectionFields($s));
        $this->handoverBoundary();
        app(InspectItems::class)->decide($w['receiver'], $s, $this->decisionFields($s));
        $this->handoverBoundary();
        $this->assertSame('approved', DB::table('route_approvals')->value('state'));
        $this->assertTrue(app(Execution::class)->current($run));
    }

    public function test_future_or_pre_receipt_time_and_missing_override_reason_are_rejected_atomically(): void
    {
        $w = $this->inspectionWork('laptop');
        $s = $w['ids'][0];
        $f = $this->inspectionFields($s, 'laptop-healthy');
        foreach ([now('UTC')->addDay(), now('UTC')->subYear()] as $time) {
            $this->receiptSignIn($w['receiver'])->postJson(route('assessments.store', $s), array_replace($f, ['observed_at' => $time->format('Y-m-d\TH:i')]))->assertUnprocessable()->assertJsonValidationErrors('observed_at');
        }
        $this->assertDatabaseCount('assessments', 0);
        app(InspectItems::class)->inspect($w['receiver'], $s, $f);
        $d = $this->decisionFields($s, 'recycle');
        unset($d['reason']);
        $this->postJson(route('assessments.decide', $s), $d)->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertDatabaseCount('decisions', 0);
        $this->handoverBoundary();
    }

    public function test_secondary_safety_observation_blocks_non_hold_decision(): void
    {
        $w = $this->inspectionWork('laptop');
        $s = $w['ids'][0];
        $f = $this->inspectionFields($s, 'laptop-healthy');
        $f['answers']['charging_risk'] = 'YES';
        app(InspectItems::class)->inspect($w['receiver'], $s, $f);
        $this->handoverBoundary();
        $this->assertTrue(DB::table('assessments')->value('hard_hold'));
        $this->receiptSignIn($w['receiver'])->postJson(route('assessments.decide', $s), $this->decisionFields($s, 'recycle'))->assertUnprocessable();
        $this->rejectDatabase(function () {
            $a = DB::table('assessments')->first();
            $d = (array) $a;
            foreach (['rule_set_id', 'schema_version', 'rule_checksum', 'recommendation', 'result', 'hard_hold', 'data_status', 'evidence_kind', 'attachment_id', 'reference_ciphertext'] as $field) {
                unset($d[$field]);
            }
            $d['id'] = Str::uuid()->toString();
            $d['version'] = 2;
            $d['source_subject_version'] = $a->subject_version;
            $d['subject_version'] = $a->subject_version + 1;
            $d['assessment_id'] = $a->id;
            $d['pathway'] = 'recycle';
            $d['override'] = true;
            DB::table('decisions')->insert($d);
        });
    }

    public function test_incomplete_direct_assessment_with_event_and_projection_fails_at_commit_boundary(): void
    {
        $w = $this->inspectionWork('laptop');
        $s = $w['ids'][0];
        app(InspectItems::class)->inspect($w['receiver'], $s, $this->inspectionFields($s, 'laptop-healthy'));
        $this->handoverBoundary();
        $this->rejectDatabase(function () use ($w, $s) {
            $a = (array) DB::table('assessments')->first();
            $a['id'] = Str::uuid()->toString();
            $a['version'] = 2;
            $a['source_subject_version'] = $a['subject_version'];
            $a['subject_version']++;
            $a['event_id'] = Str::uuid()->toString();
            $a['command_id'] = AdminMutation::command($w['receiver'], 'synthetic_direct_assessment', Str::uuid()->toString(), 'synthetic_private_hash', $a['id']);
            $event = (array) DB::table('domain_events')->where('id', DB::table('assessments')->value('event_id'))->first();
            $event['id'] = $a['event_id'];
            $event['command_id'] = $a['command_id'];
            $event['aggregate_version'] = $a['subject_version'];
            DB::table('domain_events')->insert($event);
            DB::table('assessments')->insert($a);
            DB::table('assessment_current')->where('subject_id', $s)->update(['version' => 2, 'assessment_id' => $a['id'], 'decision_id' => null]);
            DB::table('subjects')->where('id', $s)->update(['version' => $a['subject_version']]);
            // No answers: reciprocal enforcement must run, not be hidden by the outer test rollback.
        });
        $this->assertDatabaseCount('assessments', 1);
    }

    private function preserved(): array
    {
        $result = [];
        foreach (['custody_current', 'first_receipts', 'handover_receipts', 'storage_assets', 'requests', 'request_items', 'item_observations', 'route_approvals', 'route_consumptions'] as $table) {
            $result[$table] = DB::table($table)->get()->toJson();
        }

        return $result;
    }

    private function rejectDatabase(callable $mutation): void
    {
        DB::beginTransaction();
        try {
            $mutation();
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            $this->fail('Direct invalid mutation passed the enforcement boundary');
        } catch (QueryException) {
            $this->assertTrue(true);
        } finally {
            DB::rollBack();
        }
    }
}
