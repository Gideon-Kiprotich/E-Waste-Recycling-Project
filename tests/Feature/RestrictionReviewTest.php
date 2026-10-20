<?php

namespace Tests\Feature;

use App\Actions\AllocateCollector;
use App\Actions\InspectItems;
use App\Actions\PlanRoutes;
use App\Actions\RecordVisit;
use App\Actions\ReviewRequest;
use App\Actions\ReviewRestrictions;
use App\Models\User;
use App\Support\Restrictions\Effective;
use App\Support\Restrictions\Notices;
use App\Support\Routing\Execution;
use App\Support\Sanitization\Context;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\RestrictionFixtures;
use Tests\TestCase;

class RestrictionReviewTest extends TestCase
{
    use DatabaseTransactions, RestrictionFixtures;

    private function reject(callable $mutation, ?string $field = null, ?int $status = null): void
    {
        try {
            $mutation();
            $this->fail('Unsupported mutation was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field ?? 'submission', $e->errors());
        } catch (HttpException $e) {
            $this->assertSame($status ?? 409, $e->getStatusCode());
        }
    }

    public function test_same_subject_receipt_correction_requires_fresh_review_and_real_receipt(): void
    {
        $w = $this->restrictionWork();
        $before = DB::table('custody_current')->where('subject_id', $w['subject'])->first();
        $this->assertSame(['mismatch'], Effective::receiptKinds($w['subject']));
        $case = $this->openRestriction($w);
        $this->restrictionRecord($w, $case, 'assign');
        $this->restrictionRecord($w, $case, 'proposal');
        $this->assertSame(['mismatch'], Effective::receiptKinds($w['subject']));
        $this->restrictionRecord($w, $case, 'approve');
        $this->assertSame([], Effective::receiptKinds($w['subject']));
        $this->assertSame('stale', DB::table('requests')->where('id', $w['request'])->value('review_state'));
        $this->assertEquals($before, DB::table('custody_current')->where('subject_id', $w['subject'])->first());
        $this->assertDatabaseCount('first_receipts', 0);
        $this->reject(fn () => app(RecordVisit::class)->record($w['observer'], $w['request'], $this->visitFields($w['request'])), null, 409);
        app(ReviewRequest::class)->decide($w['admin'], $w['request'], ['decision' => 'ready', 'reason' => 'Fresh synthetic review after factual correction', 'expected_version' => DB::table('requests')->where('id', $w['request'])->value('version'), 'idempotency_key' => Str::uuid()->toString()]);
        app(RecordVisit::class)->record($w['observer'], $w['request'], $this->visitFields($w['request']));
        $this->handoverBoundary();
        $this->assertDatabaseCount('first_receipts', 1);
        $this->assertSame(1, DB::table('receipt_discrepancies')->where('kind', 'mismatch')->count());
        $this->assertSame($w['owner']->id, DB::table('items')->where('subject_id', $w['subject'])->value('owner_user_id'));
    }

    public function test_closed_without_change_and_continued_review_do_not_clear_safety(): void
    {
        $w = $this->restrictionWork('receipt', 'safety_hold');
        $case = $this->openRestriction($w);
        $this->restrictionRecord($w, $case, 'continue');
        $this->assertSame(['safety_hold'], Effective::receiptKinds($w['subject']));
        $this->restrictionRecord($w, $case, 'close');
        $this->assertSame(['safety_hold'], Effective::receiptKinds($w['subject']));
        $this->assertDatabaseCount('restriction_supersessions', 0);
        $this->assertDatabaseCount('restriction_epochs', 0);
    }

    public function test_inspection_fact_supersession_preserves_original_and_requires_new_inspection(): void
    {
        $w = $this->restrictionWork('inspection', 'safety_concern');
        $original = DB::table('assessments')->where('id', $w['source']->source_id)->first();
        $case = $this->openRestriction($w);
        $this->restrictionRecord($w, $case, 'assign');
        $this->restrictionRecord($w, $case, 'proposal');
        $this->restrictionRecord($w, $case, 'approve');
        $this->assertFalse(Effective::inspectionBlocked($w['subject']));
        $this->assertEquals($original, DB::table('assessments')->where('id', $original->id)->first());
        $this->reject(fn () => app(InspectItems::class)->decide($w['investigator'], $w['subject'], $this->decisionFields($w['subject'])), 'pathway');
        app(InspectItems::class)->inspect($w['investigator'], $w['subject'], $this->inspectionFields($w['subject']));
        app(InspectItems::class)->decide($w['investigator'], $w['subject'], $this->decisionFields($w['subject']));
        $this->handoverBoundary();
        $this->assertSame(1, (int) DB::selectOne('SELECT restriction_epoch(?) AS version', [$w['subject']])->version);
    }

    public function test_unknown_and_remediation_findings_cannot_be_approved(): void
    {
        $w = $this->restrictionWork();
        $case = $this->openRestriction($w);
        $this->restrictionRecord($w, $case, 'assign');
        $this->restrictionRecord($w, $case, 'proposal', ['proposed_value' => 'UNKNOWN']);
        $this->reject(fn () => $this->restrictionRecord($w, $case, 'approve'), 'submission');
        $fields = $this->restrictionFields($w, 'proposal', $case);
        $fields['checks']['no_remediation'] = 'NO';
        $this->restrictionRecord($w, $case, 'proposal', $fields);
        $this->reject(fn () => $this->restrictionRecord($w, $case, 'approve'), 'submission');
        $this->assertSame(['mismatch'], Effective::receiptKinds($w['subject']));
    }

    public function test_wrong_subject_and_protected_null_fields_are_rejected_without_state_changes(): void
    {
        $w = $this->restrictionWork();
        $case = $this->openRestriction($w);
        $this->restrictionRecord($w, $case, 'assign');
        $this->reject(fn () => $this->restrictionRecord($w, $case, 'proposal', ['identity_code' => 'EWC-'.str_repeat('F', 32)]), 'submission');
        foreach (['subject_id' => null, 'custody' => [], 'owner_id' => null, 'source_event_id' => []] as $key => $value) {
            $this->reject(fn () => $this->restrictionRecord($w, $case, 'proposal', [$key => $value]), 'submission');
        }
        $this->assertDatabaseCount('restriction_records', 2);
        $this->assertDatabaseCount('restriction_supersessions', 0);
    }

    public function test_approval_clears_one_fact_and_independent_hazards_remain(): void
    {
        $w = $this->restrictionWork('inspection', 'safety_concern');
        $fields = $this->inspectionFields($w['subject']);
        $fields['answers']['safety_concern'] = 'YES';
        $fields['answers']['thermal_issue'] = 'YES';
        app(InspectItems::class)->inspect($w['investigator'], $w['subject'], $fields);
        $this->handoverBoundary();
        $w['source'] = DB::table('restriction_sources')->where('subject_id', $w['subject'])->where('fact_key', 'thermal_issue')->orderByDesc('source_version')->firstOrFail();
        $case = $this->openRestriction($w);
        $this->restrictionRecord($w, $case, 'assign');
        $this->restrictionRecord($w, $case, 'proposal');
        $this->restrictionRecord($w, $case, 'approve');
        $this->assertTrue(Effective::inspectionBlocked($w['subject']));
        $this->assertSame(2, DB::table('effective_restrictions')->where('subject_id', $w['subject'])->count());
        $this->assertSame(1, DB::table('restriction_supersessions')->count());
    }

    public function test_new_source_restriction_stales_an_old_proposal(): void
    {
        $w = $this->restrictionWork('inspection', 'safety_concern');
        $case = $this->openRestriction($w);
        $this->restrictionRecord($w, $case, 'assign');
        $this->restrictionRecord($w, $case, 'proposal');
        $review = $this->restrictionFields($w, 'approve', $case);
        $fields = $this->inspectionFields($w['subject']);
        $fields['answers']['thermal_issue'] = 'YES';
        app(InspectItems::class)->inspect($w['investigator'], $w['subject'], $fields);
        $this->reject(fn () => app(ReviewRestrictions::class)->record($w['admin'], $case, 'approve', $review), null, 409);
        $this->reject(fn () => $this->restrictionRecord($w, $case, 'approve'), 'submission');
        $this->assertTrue(Effective::inspectionBlocked($w['subject']));
    }

    public function test_author_cannot_approve_even_after_a_role_change(): void
    {
        $w = $this->restrictionWork();
        $case = $this->openRestriction($w);
        $this->restrictionRecord($w, $case, 'assign');
        $this->restrictionRecord($w, $case, 'proposal');
        DB::table('memberships')->where('user_id', $w['investigator']->id)->update(['role' => 'administrator', 'place_id' => null, 'organization_id' => null, 'version' => DB::raw('version+1')]);
        $author = $w['investigator']->fresh(['membership']);
        $this->reject(fn () => $this->restrictionRecord($w, $case, 'approve', [], $author), 'submission');
        $this->assertDatabaseCount('restriction_supersessions', 0);
    }

    public function test_revocation_removes_case_and_old_evidence_access_and_prevents_approval(): void
    {
        $w = $this->restrictionWork();
        $case = $this->openRestriction($w);
        $this->restrictionRecord($w, $case, 'assign');
        $fields = $this->restrictionFields($w, 'proposal', $case);
        $this->restrictionRecord($w, $case, 'proposal');
        $this->restrictionRecord($w, $case, 'revoke');
        $this->receiptSignIn($w['investigator'])->get(route('restrictions.show', $case))->assertNotFound();
        $this->reject(fn () => app(ReviewRestrictions::class)->record($w['investigator'], $case, 'proposal', $fields), null, 404);
        $this->reject(fn () => $this->restrictionRecord($w, $case, 'approve'), 'submission');
    }

    public function test_assignment_without_explicit_pre_receipt_grant_has_no_investigation_authority(): void
    {
        $w = $this->restrictionWork();
        $case = $this->openRestriction($w);
        $this->reject(fn () => $this->restrictionRecord($w, $case, 'assign', ['pre_receipt' => '0']), 'submission');
        $this->receiptSignIn($w['investigator'])->get(route('restrictions.show', $case))->assertNotFound();
        $this->restrictionRecord($w, $case, 'assign');
        $this->receiptSignIn($w['investigator'])->get(route('restrictions.show', $case))->assertOk()->assertSee('Investigation-only grant');
        $this->get(route('items.show', $w['subject']))->assertForbidden();
        $this->get(route('assessments.show', $w['subject']))->assertNotFound();
        $this->reject(fn () => app(RecordVisit::class)->record($w['investigator'], $w['request'], $this->visitFields($w['request'])), null, 404);
    }

    public function test_original_observer_clarification_does_not_become_a_technical_proposal(): void
    {
        $w = $this->restrictionWork();
        $case = $this->openRestriction($w);
        $this->restrictionRecord($w, $case, 'clarify', [], $w['observer']);
        $this->receiptSignIn($w['observer'])->get(route('restrictions.show', $case))->assertOk();
        $this->reject(fn () => $this->restrictionRecord($w, $case, 'proposal', [], $w['observer']), 'submission');
        $this->assertDatabaseCount('restriction_supersessions', 0);
    }

    public function test_identical_retry_returns_original_without_reactivation_and_changed_payload_conflicts(): void
    {
        $w = $this->restrictionWork();
        $case = $this->openRestriction($w);
        $this->restrictionRecord($w, $case, 'assign');
        $fields = $this->restrictionFields($w, 'proposal', $case);
        app(ReviewRestrictions::class)->record($w['investigator'], $case, 'proposal', $fields);
        $this->handoverBoundary();
        $this->assertSame($case, app(ReviewRestrictions::class)->record($w['investigator'], $case, 'proposal', $fields));
        $this->reject(fn () => app(ReviewRestrictions::class)->record($w['investigator'], $case, 'proposal', array_replace($fields, ['reason' => 'Altered synthetic payload'])), null, 409);
        $approval = $this->restrictionFields($w, 'approve', $case);
        app(ReviewRestrictions::class)->record($w['admin'], $case, 'approve', $approval);
        $this->handoverBoundary();
        $this->assertSame($case, app(ReviewRestrictions::class)->record($w['admin'], $case, 'approve', $approval));
        $this->reject(fn () => app(ReviewRestrictions::class)->record($w['investigator'], $case, 'proposal', $fields), null, 404);
        $this->assertDatabaseCount('restriction_supersessions', 1);
        $this->assertSame(1, (int) DB::selectOne('SELECT restriction_epoch(?) AS v', [$w['subject']])->v);
    }

    public function test_unsupported_work_case_has_investigation_but_no_supersession(): void
    {
        $work = $this->mediaWork();
        $this->reuseRecord($work, 'work', ['configuration' => 'UNKNOWN']);
        $source = DB::table('restriction_sources')->where('subject_id', $work['ids'][0])->where('source_kind', 'work')->firstOrFail();
        $w = ['admin' => $work['admin'], 'owner' => $work['owner'], 'investigator' => $work['receiver'], 'subject' => $work['ids'][0], 'source' => $source];
        $case = $this->openRestriction($w);
        $this->restrictionRecord($w, $case, 'assign', ['pre_receipt' => '0']);
        $this->restrictionRecord($w, $case, 'note', [], $w['investigator']);
        $this->receiptSignIn($w['admin'])->get(route('restrictions.show', $case))->assertOk()->assertSee('Resolution unavailable')->assertDontSee('Approve evidenced fact supersession');
        $this->reject(fn () => $this->restrictionRecord($w, $case, 'approve', ['proposal_id' => Str::uuid()->toString()]), 'submission');
    }

    public function test_source_projection_and_page_reads_create_no_operational_cases_or_events(): void
    {
        $w = $this->restrictionWork();
        $before = [];
        foreach (['restriction_cases', 'restriction_records', 'commands', 'domain_events', 'notification_outbox', 'custody_current', 'first_receipts'] as $table) {
            $before[$table] = DB::table($table)->get()->toJson();
        }
        $this->receiptSignIn($w['admin'])->get(route('restrictions.index'))->assertOk()->assertSee('Source restrictions');
        $s = $w['source'];
        $this->get(route('restrictions.source', [$s->source_kind, $s->source_id, $s->fact_key]))->assertOk()->assertSee('Open investigation');
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->get()->toJson(), $table);
        }
    }

    public function test_evidence_requires_explicit_grant_and_old_links_end_at_closure(): void
    {
        Storage::fake('directory_evidence');
        $w = $this->restrictionWork('receipt', 'mismatch', true);
        $case = $this->openRestriction($w);
        $this->restrictionRecord($w, $case, 'assign');
        $url = route('restrictions.evidence', [$case, $w['source']->source_attachment_id]);
        $this->receiptSignIn($w['investigator'])->get($url)->assertNotFound();
        $this->restrictionRecord($w, $case, 'assign', ['allowed_evidence' => [$w['source']->source_attachment_id]]);
        $this->receiptSignIn($w['investigator'])->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->receiptSignIn($w['owner'])->get($url)->assertNotFound();
        $this->restrictionRecord($w, $case, 'close');
        $this->receiptSignIn($w['investigator'])->get($url)->assertNotFound();
        $this->receiptSignIn($w['admin'])->get($url)->assertOk();
    }

    public function test_scope_and_reviewer_revocation_reject_old_mutations(): void
    {
        $w = $this->restrictionWork();
        $case = $this->openRestriction($w);
        $this->restrictionRecord($w, $case, 'assign');
        $this->restrictionRecord($w, $case, 'proposal');
        $fields = $this->restrictionFields($w, 'approve', $case);
        DB::table('memberships')->where('user_id', $w['admin']->id)->update(['active' => false, 'version' => DB::raw('version+1')]);
        $this->reject(fn () => app(ReviewRestrictions::class)->record($w['admin'], $case, 'approve', $fields), null, 403);
        DB::table('memberships')->where('user_id', $w['investigator']->id)->update(['version' => DB::raw('version+1')]);
        $this->receiptSignIn($w['investigator'])->get(route('restrictions.show', $case))->assertNotFound();
        $this->assertDatabaseCount('restriction_supersessions', 0);
    }

    public function test_case_notices_are_scoped_deduplicated_and_do_not_drain_other_outbox(): void
    {
        $w = $this->restrictionWork();
        $case = $this->openRestriction($w);
        $this->restrictionRecord($w, $case, 'assign');
        $unrelated = DB::table('notification_outbox')->whereNotIn('event_id', DB::table('restriction_records')->select('event_id'))->where('state', 'pending')->count();
        $delivery = app(Notices::class);
        $this->assertGreaterThan(0, $delivery->deliver());
        $count = DB::table('restriction_notifications')->count();
        $this->assertSame(0, $delivery->deliver());
        $this->assertSame($count, DB::table('restriction_notifications')->count());
        $this->assertSame($unrelated, DB::table('notification_outbox')->whereNotIn('event_id', DB::table('restriction_records')->select('event_id'))->where('state', 'pending')->count());
        $notice = DB::table('restriction_notifications')->where('recipient_id', $w['owner']->id)->firstOrFail();
        $this->receiptSignIn($w['investigator'])->post(route('restrictions.notices.read', $notice->id))->assertNotFound();
        $this->receiptSignIn($w['owner'])->post(route('restrictions.notices.read', $notice->id))->assertRedirect(route('restrictions.notices'));
        $this->handoverBoundary();
        $this->assertNotNull(DB::table('restriction_notifications')->where('id', $notice->id)->value('read_at'));
    }

    public function test_consumer_milestones_hide_private_case_facts_and_other_owners(): void
    {
        $w = $this->restrictionWork();
        $case = $this->openRestriction($w);
        $this->restrictionRecord($w, $case, 'assign');
        $this->restrictionRecord($w, $case, 'proposal');
        $this->receiptSignIn($w['owner'])->get(route('restrictions.show', $case))->assertOk()->assertSee('restriction remains in effect')->assertDontSee('Private synthetic')->assertDontSee('Synthetic observation log')->assertDontSee($w['source']->source_event_id)->assertDontSee('Record technical findings');
        $other = User::factory()->create();
        $this->receiptSignIn($other)->get(route('restrictions.show', $case))->assertNotFound();
    }

    public function test_supported_http_forms_and_error_associations_keep_only_command_input(): void
    {
        $w = $this->restrictionWork();
        $s = $w['source'];
        $this->receiptSignIn($w['admin'])->post(route('restrictions.open', [$s->source_kind, $s->source_id, $s->fact_key]), $this->restrictionFields($w, 'open'))->assertRedirect();
        $case = DB::table('restriction_cases')->value('id');
        $this->post(route('restrictions.store', [$case, 'assign']), $this->restrictionFields($w, 'assign', $case))->assertRedirect(route('restrictions.show', $case));
        $fields = $this->restrictionFields($w, 'proposal', $case);
        unset($fields['basis']);
        $this->receiptSignIn($w['investigator'])->from(route('restrictions.show', ['case' => $case, 'action' => 'proposal']))->post(route('restrictions.store', [$case, 'proposal']), $fields)->assertRedirect()->assertSessionHasErrors('basis')->assertSessionHasInput('idempotency_key');
        $this->assertSame(['idempotency_key' => $fields['idempotency_key']], session()->getOldInput());
        $this->withCookie(session()->getName(), session()->getId())->get(route('restrictions.show', ['case' => $case, 'action' => 'proposal']))->assertOk()->assertSee('data-error-summary', false)->assertSee('id="error-basis"', false)->assertSee('aria-describedby="error-basis"', false)->assertDontSee('Synthetic observation log');
    }

    public function test_direct_invalid_projection_supersession_and_stamp_writes_fail_at_constraints(): void
    {
        $w = $this->restrictionWork('inspection', 'safety_concern');
        $case = $this->openRestriction($w);
        foreach ([
            fn () => DB::table('restriction_cases')->where('id', $case)->update(['state' => 'superseded', 'active' => false]),
            fn () => DB::table('restriction_records')->where('case_id', $case)->update(['kind' => 'approve']),
            fn () => DB::table('restriction_cases')->where('id', $case)->delete(),
            fn () => DB::table('restriction_epochs')->insert(['subject_id' => $w['subject'], 'version' => 1, 'record_id' => DB::table('restriction_cases')->where('id', $case)->value('record_id')]),
        ] as $mutation) {
            $this->rejectDatabase($mutation);
        }
        $assessment = DB::table('assessments')->where('subject_id', $w['subject'])->firstOrFail();
        $this->rejectDatabase(fn () => DB::table('restriction_stamps')->insert(['kind' => 'assessments', 'record_id' => $assessment->id, 'subject_id' => $w['subject'], 'version' => 0]));
        $this->assertTrue(Effective::inspectionBlocked($w['subject']));
    }

    public function test_completed_work_is_preserved_and_receives_a_blocking_dependent_exception(): void
    {
        $work = $this->mediaWork();
        $this->reuseRecord($work);
        $before = DB::table('refurbishment_records')->get()->toJson();
        $subject = $work['ids'][0];
        $fields = $this->inspectionFields($subject);
        $fields['answers']['safety_concern'] = 'YES';
        app(InspectItems::class)->inspect($work['receiver'], $subject, $fields);
        $this->handoverBoundary();
        $source = DB::table('restriction_sources')->where('subject_id', $subject)->where('source_kind', 'inspection')->where('fact_key', 'safety_concern')->firstOrFail();
        $w = ['admin' => $work['admin'], 'owner' => $work['owner'], 'investigator' => $work['receiver'], 'subject' => $subject, 'source' => $source];
        $case = $this->openRestriction($w);
        $this->restrictionRecord($w, $case, 'assign');
        $this->restrictionRecord($w, $case, 'proposal');
        $this->restrictionRecord($w, $case, 'approve');
        $this->assertFalse(Effective::inspectionBlocked($subject));
        $this->assertGreaterThan(0, DB::table('restriction_impacts')->where('subject_id', $subject)->count());
        $this->assertSame($before, DB::table('refurbishment_records')->get()->toJson());
        $this->assertFalse(Effective::freshInspection($subject));
        $this->assertTrue(DB::table('restriction_sources')->where('source_kind', 'dependent')->where('subject_id', $subject)->exists());
    }

    public function test_parent_correction_stales_media_inventory_authority_and_assurance_transitively(): void
    {
        $work = $this->inventoryWork();
        $medium = $this->identifyMedium($work);
        $this->inventoryRecord($work, 'attest');
        $this->authorizeMedium($work, $medium);
        $this->mediaSatisfied($this->mediumWork($work, $medium));
        $custody = DB::table('custody_current')->orderBy('subject_id')->get()->toJson();
        $assurance = DB::table('sanitization_records')->get()->toJson();
        $fields = $this->inspectionFields($work['ids'][0], 'laptop-healthy');
        $fields['answers']['safety_concern'] = 'YES';
        app(InspectItems::class)->inspect($work['receiver'], $work['ids'][0], $fields);
        $this->handoverBoundary();
        $source = DB::table('restriction_sources')->where('subject_id', $work['ids'][0])->where('source_kind', 'inspection')->where('fact_key', 'safety_concern')->firstOrFail();
        $w = ['admin' => $work['admin'], 'owner' => $work['owner'], 'investigator' => $work['receiver'], 'subject' => $work['ids'][0], 'source' => $source];
        $case = $this->openRestriction($w);
        $this->restrictionRecord($w, $case, 'assign');
        $this->restrictionRecord($w, $case, 'proposal');
        $this->restrictionRecord($w, $case, 'approve');
        $this->assertSame(1, (int) DB::selectOne('SELECT restriction_epoch(?) AS epoch', [$medium])->epoch);
        $this->assertSame('review_required', app(Context::class)->state($medium));
        $this->assertSame('blocked', app(\App\Support\MediaInventory\Context::class)->coverage($w['subject'])['status']);
        $this->assertSame($custody, DB::table('custody_current')->orderBy('subject_id')->get()->toJson());
        $this->assertSame($assurance, DB::table('sanitization_records')->get()->toJson());
    }

    public function test_fresh_inventory_and_media_authorization_can_follow_review_without_reviving_old_authority(): void
    {
        $work = $this->inventoryWork();
        $medium = $this->identifyMedium($work);
        $this->inventoryRecord($work, 'attest');
        $this->authorizeMedium($work, $medium);
        $oldAuthorization = DB::table('media_authorization_current')->where('subject_id', $medium)->value('record_id');
        $fields = $this->inspectionFields($work['ids'][0], 'laptop-healthy');
        $fields['answers']['safety_concern'] = 'YES';
        app(InspectItems::class)->inspect($work['receiver'], $work['ids'][0], $fields);
        $this->handoverBoundary();
        $source = DB::table('restriction_sources')->where('subject_id', $work['ids'][0])->where('source_kind', 'inspection')->where('fact_key', 'safety_concern')->firstOrFail();
        $w = ['admin' => $work['admin'], 'owner' => $work['owner'], 'investigator' => $work['receiver'], 'subject' => $work['ids'][0], 'source' => $source];
        $case = $this->openRestriction($w);
        $this->restrictionRecord($w, $case, 'assign');
        $this->restrictionRecord($w, $case, 'proposal');
        $this->restrictionRecord($w, $case, 'approve');
        $this->assertDatabaseCount('restriction_impacts', 0);
        $this->assertFalse((bool) DB::selectOne('SELECT media_authorization_applicable(a) AS valid FROM media_authorizations a WHERE id=?', [$oldAuthorization])->valid);
        app(InspectItems::class)->inspect($work['receiver'], $w['subject'], $this->inspectionFields($w['subject'], 'laptop-healthy'));
        app(InspectItems::class)->decide($work['receiver'], $w['subject'], $this->decisionFields($w['subject']));
        $this->inventoryRecord($work, 'attest');
        $this->authorizeMedium($work, $medium);
        $this->assertNotSame($oldAuthorization, DB::table('media_authorization_current')->where('subject_id', $medium)->value('record_id'));
        $this->assertDatabaseCount('sanitization_records', 0);
        $this->mediaSatisfied($this->mediumWork($work, $medium));
        $this->assertSame('satisfied', app(Context::class)->state($medium));
    }

    public function test_pickup_correction_requires_fresh_offer_acceptance_and_route_before_actual_receipt(): void
    {
        [$admin,$owner,$collector,$route,$ordered] = $this->pickupWork();
        $request = DB::table('assignments')->where('id', $ordered[0])->value('request_id');
        $subject = DB::table('request_items')->where('request_id', $request)->value('item_id');
        app(RecordVisit::class)->record($collector, $request, $this->visitFields($request, ['mismatch']));
        app(Execution::class)->finish($collector, $route, ['idempotency_key' => Str::uuid()->toString(), 'unfinished' => [], 'confirm' => '1', 'reason' => 'Synthetic failed visit tour ended']);
        $point = DB::table('places')->where('code', 'synthetic-study-all-ict')->value('id');
        $investigator = $this->pointStaff($admin, $this->facility($point));
        $this->handoverBoundary();
        $source = DB::table('restriction_sources')->where('subject_id', $subject)->where('source_kind', 'receipt')->firstOrFail();
        $w = compact('admin', 'owner', 'investigator', 'subject', 'source');
        $before = DB::table('request_items')->where('request_id', $request)->get()->toJson();
        $case = $this->openRestriction($w);
        $this->restrictionRecord($w, $case, 'assign');
        $this->restrictionRecord($w, $case, 'proposal');
        $this->restrictionRecord($w, $case, 'approve');
        $this->assertSame($before, DB::table('request_items')->where('request_id', $request)->get()->toJson());
        $this->assertDatabaseCount('first_receipts', 0);
        $this->ready($admin, $request);
        $allocation = app(AllocateCollector::class);
        $offer = $allocation->allocate($admin, $request, $this->offerFields($request, $collector, $point));
        $allocation->respond($collector, $offer, $this->responseFields($offer));
        $this->reject(fn () => app(RecordVisit::class)->record($collector, $request, $this->visitFields($request)), null, 409);
        $depot = DB::table('places')->where('kind', 'depot')->value('id');
        $vehicle = DB::table('vehicles')->value('id');
        $planner = app(PlanRoutes::class);
        $run = $planner->create($admin, $this->routeFields($depot, $vehicle, [$offer]));
        $planner->approve($admin, $run, $this->approvalFields());
        app(RecordVisit::class)->record($collector, $request, $this->visitFields($request));
        $this->handoverBoundary();
        $this->assertDatabaseCount('first_receipts', 1);
        $this->assertSame('finished', DB::table('route_approvals')->where('run_id', DB::table('routes')->where('id', $route)->value('run_id'))->value('state'));
    }

    public function test_review_ui_shows_category_and_admin_author_without_contact_or_ineligible_choices(): void
    {
        $w = $this->restrictionWork();
        $w['investigator']->update(['display_name' => 'Synthetic technical investigator']);
        $case = $this->openRestriction($w);
        $this->restrictionRecord($w, $case, 'assign');
        $this->restrictionRecord($w, $case, 'proposal');
        $this->receiptSignIn($w['admin'])->get(route('restrictions.show', ['case' => $case, 'action' => 'assign']))->assertOk()->assertSee('Physical category')->assertSee('Solid-state drive')->assertSee('Synthetic technical investigator')->assertSee('Recorded by')->assertDontSee('value="'.$w['admin']->id.'"', false)->assertDontSee($w['owner']->email);
        $this->receiptSignIn($w['owner'])->get(route('restrictions.show', $case))->assertOk()->assertDontSee('Synthetic technical investigator')->assertDontSee('Recorded by');
    }

    public function test_stale_form_has_case_reload_and_superseded_source_stays_read_only(): void
    {
        $w = $this->restrictionWork();
        $case = $this->openRestriction($w);
        $stale = $this->restrictionFields($w, 'continue', $case);
        $this->restrictionRecord($w, $case, 'assign');
        $this->receiptSignIn($w['admin'])->post(route('restrictions.store', [$case, 'continue']), $stale)->assertConflict()->assertSee('Reload current investigation')->assertSee(route('restrictions.show', $case), false);
        $this->restrictionRecord($w, $case, 'proposal');
        $this->restrictionRecord($w, $case, 'approve');
        $s = $w['source'];
        $this->receiptSignIn($w['admin'])->get(route('restrictions.source', [$s->source_kind, $s->source_id, $s->fact_key]))->assertOk()->assertSee('retained as history')->assertDontSee('type="submit">Open investigation', false);
    }

    private function rejectDatabase(callable $mutation): void
    {
        DB::beginTransaction();
        try {
            $mutation();
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            $this->fail('Direct invalid write passed the enforcement boundary.');
        } catch (\PDOException) {
            $this->assertTrue(true);
        } finally {
            DB::rollBack();
        }
    }
}
