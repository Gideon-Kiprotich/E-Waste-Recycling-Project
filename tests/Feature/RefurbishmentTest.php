<?php

namespace Tests\Feature;

use App\Actions\InspectItems;
use App\Actions\RefurbishItems;
use App\Models\User;
use App\Support\Collection\AdminMutation;
use App\Support\Refurbishment\Context;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\RefurbishmentFixtures;
use Tests\TestCase;

class RefurbishmentTest extends TestCase
{
    use DatabaseTransactions, RefurbishmentFixtures;

    public function test_standalone_drive_owner_return_is_acknowledged_once_and_preserves_history(): void
    {
        $w = $this->mediaWork();
        $this->mediaSatisfied($w);
        $before = $this->mediaPreservation();
        $this->reusePassed($w);
        $authorization = $this->reuseRecord($w, 'authorize');
        $s = $w['ids'][0];
        $this->assertSame('assessed', DB::table('items')->where('subject_id', $s)->value('processing_state'));
        $fields = $this->reuseFields($w, 'acknowledge', true);
        $id = app(RefurbishItems::class)->record($w['owner'], $s, 'acknowledge', $fields);
        $this->handoverBoundary();
        $this->assertSame($id, app(RefurbishItems::class)->record($w['owner'], $s, 'acknowledge', $fields));
        $this->assertSame('released', DB::table('items')->where('subject_id', $s)->value('processing_state'));
        $this->assertSame($w['owner']->id, DB::table('items')->where('subject_id', $s)->value('owner_user_id'));
        $this->assertSame($w['owner']->id, DB::table('custody_current as cc')->join('parties as p', 'p.id', '=', 'cc.party_id')->where('cc.subject_id', $s)->value('p.user_id'));
        $this->assertDatabaseCount('first_receipts', 1);
        $this->assertDatabaseCount('handover_receipts', 1);
        $this->assertDatabaseCount('reuse_return_items', 1);
        foreach (['first_receipts', 'handover_receipts', 'assessments', 'assessment_answers', 'decisions', 'requests', 'request_items', 'route_claims', 'route_approvals', 'route_consumptions', 'notification_outbox'] as $t) {
            $this->assertSame($before[$t], DB::table($t)->get()->toJson(), $t);
        }
        $this->assertSame('acknowledged', DB::table('reuse_authorizations')->where('id', $authorization)->value('state'));
        $this->expectException(HttpException::class);
        app(RefurbishItems::class)->record($w['owner'], $s, 'acknowledge', array_replace($fields, ['reason' => 'Changed payload']));
    }

    public function test_parent_and_all_canonical_media_return_atomically_without_detachment(): void
    {
        $w = $this->reuseParent(2);
        $parent = $w['ids'][0];
        $media = DB::table('media_origins')->pluck('subject_id')->all();
        $origins = DB::table('media_origins')->get()->toJson();
        $placements = DB::table('media_inventory_entries')->get()->toJson();
        $assurance = DB::table('sanitization_records')->get()->toJson();
        $this->reusePassed($w);
        $this->reuseRecord($w, 'authorize');
        $this->reuseRecord($w, 'acknowledge', [], true);
        $this->assertSame(['released', 'released', 'released'], DB::table('items')->whereIn('subject_id', [$parent, ...$media])->pluck('processing_state')->all());
        $this->assertDatabaseCount('reuse_return_items', 3);
        $this->assertDatabaseCount('first_receipts', 1);
        $this->assertDatabaseCount('handover_receipts', 1);
        $this->assertSame($origins, DB::table('media_origins')->get()->toJson());
        $this->assertSame($placements, DB::table('media_inventory_entries')->get()->toJson());
        $this->assertSame($assurance, DB::table('sanitization_records')->get()->toJson());
        foreach ([$parent, ...$media] as $s) {
            $this->assertSame($w['owner']->id, DB::table('custody_current as c')->join('parties as p', 'p.id', '=', 'c.party_id')->where('c.subject_id', $s)->value('p.user_id'));
        }
    }

    public static function profileCases(): array
    {
        return array_map(fn ($key) => [$key], ['laptop', 'desktop', 'phone', 'flat_monitor', 'printer', 'router', 'keyboard', 'hdd', 'ssd', 'ict_cooling_fan']);
    }

    #[DataProvider('profileCases')]
    public function test_each_frozen_profile_records_pass_failure_unknown_and_permitted_na(string $key): void
    {
        $category = in_array($key, ['router', 'keyboard'], true) ? 'peripheral' : (in_array($key, ['hdd', 'ssd', 'ict_cooling_fan'], true) ? 'standalone_part' : $key);
        $w = $this->inspectionWork($category, $category !== $key ? $key : null);
        $s = $w['ids'][0];
        app(InspectItems::class)->inspect($w['receiver'], $s, $this->inspectionFields($s, $key.'-healthy'));
        app(InspectItems::class)->decide($w['receiver'], $s, $this->decisionFields($s));
        $this->handoverBoundary();
        $this->reusePassed($w);
        $this->assertTrue(DB::selectOne('SELECT refurbishment_test_ready(?) AS ready', [$s])->ready);
        foreach (['FAIL' => 'failed', 'UNKNOWN' => 'incomplete'] as $value => $result) {
            DB::beginTransaction();
            try {
                $f = $this->reuseFields($w, 'test');
                $f['answers'][array_key_last($f['answers'])] = $value;
                $id = $this->reuseRecord($w, 'test', $f);
                $this->assertSame($result, DB::table('refurbishment_records')->where('id', $id)->value('result'));
                $this->assertFalse(DB::selectOne('SELECT refurbishment_ready(?,?) AS ready', [$s, $w['receiver']->id])->ready);
            } finally {
                DB::rollBack();
            }
        }
        $f = $this->reuseFields($w, 'test');
        $f['answers']['safety'] = 'NOT_APPLICABLE';
        $this->rejectValidation(fn () => app(RefurbishItems::class)->record($w['receiver'], $s, 'test', $f));
        if ($key === 'keyboard') {
            $f = $this->reuseFields($w, 'test');
            $f['answers']['power'] = 'NOT_APPLICABLE';
            $this->rejectValidation(fn () => app(RefurbishItems::class)->record($w['receiver'], $s, 'test', $f));
            $f['na_reasons']['power'] = 'Synthetic passive keyboard, appropriate compatible host; explicit evidence';
            $this->reuseRecord($w, 'test', $f);
            $this->assertTrue(DB::selectOne('SELECT refurbishment_test_ready(?) AS ready', [$s])->ready);
        }
    }

    public function test_new_work_invalidates_pass_and_failed_test_requires_fresh_inspection(): void
    {
        $w = $this->mediaWork();
        $s = $w['ids'][0];
        $this->reusePassed($w);
        $this->reuseRecord($w);
        $this->assertFalse(DB::selectOne('SELECT refurbishment_test_ready(?) AS ready', [$s])->ready);
        $f = $this->reuseFields($w, 'test');
        $f['answers']['diagnostics'] = 'FAIL';
        $this->reuseRecord($w, 'test', $f);
        $this->rejectValidation(fn () => $this->reuseRecord($w));
        app(InspectItems::class)->inspect($w['receiver'], $s, $this->inspectionFields($s));
        app(InspectItems::class)->decide($w['receiver'], $s, $this->decisionFields($s));
        $this->handoverBoundary();
        $this->reusePassed($w);
        $this->assertTrue(DB::selectOne('SELECT refurbishment_test_ready(?) AS ready', [$s])->ready);
        $this->assertSame('failed', DB::table('refurbishment_records')->where('kind', 'test')->where('result', 'failed')->value('result'));
    }

    public function test_fan_needs_explicit_evidenced_na_and_owner_return(): void
    {
        $w = $this->inspectionWork('standalone_part', 'ict_cooling_fan');
        $s = $w['ids'][0];
        app(InspectItems::class)->inspect($w['receiver'], $s, $this->inspectionFields($s, 'ict_cooling_fan-healthy'));
        app(InspectItems::class)->decide($w['receiver'], $s, $this->decisionFields($s));
        $this->handoverBoundary();
        $this->reusePassed($w);
        $this->rejectValidation(fn () => $this->reuseRecord($w, 'authorize'));
        $this->reuseRecord($w, 'fan_na');
        $this->assertTrue(DB::selectOne('SELECT refurbishment_data_ready(?) AS ready', [$s])->ready);
        $this->reuseRecord($w, 'authorize');
        $this->reuseRecord($w, 'acknowledge', [], true);
        $this->assertSame('released', DB::table('items')->where('subject_id', $s)->value('processing_state'));
    }

    public function test_whole_device_evidenced_na_does_not_skip_inventory(): void
    {
        $w = $this->inventoryWork('NO');
        $this->reusePassed($w);
        $this->rejectValidation(fn () => $this->reuseRecord($w, 'authorize'));
        $this->inventoryRecord($w, 'attest');
        // New observations need a fresh current test, without inventing repeated physical work.
        $this->reuseRecord($w, 'test');
        $this->reuseRecord($w, 'authorize');
        $this->reuseRecord($w, 'acknowledge', [], true);
        $this->assertDatabaseCount('reuse_return_items', 1);
    }

    public function test_parent_manifest_rejects_partial_and_foreign_acknowledgment(): void
    {
        $w = $this->reuseParent();
        $this->reusePassed($w);
        $this->reuseRecord($w, 'authorize');
        $before = DB::table('custody_current')->get()->toJson();
        $this->rejectValidation(fn () => $this->reuseRecord($w, 'acknowledge', ['received' => [$w['ids'][0]]], true));
        $other = User::factory()->create();
        try {
            app(RefurbishItems::class)->record($other, $w['ids'][0], 'acknowledge', $this->reuseFields($w, 'acknowledge', true));
            $this->fail('Other owner must be denied');
        } catch (ModelNotFoundException|RecordNotFoundException|HttpException $e) {
            $this->assertTrue(true);
        }
        try {
            app(RefurbishItems::class)->record($w['receiver'], $w['ids'][0], 'acknowledge', $this->reuseFields($w, 'acknowledge', true));
            $this->fail('Staff cannot impersonate owner receipt');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->reuseRecord($w, 'exception', [], true);
        $this->assertSame($before, DB::table('custody_current')->get()->toJson());
        $this->assertDatabaseCount('reuse_return_items', 0);
        $this->rejectValidation(fn () => $this->reuseRecord($w, 'authorize'));
    }

    public function test_new_assurance_invalidates_authorization_and_old_success_cannot_revive_it(): void
    {
        $w = $this->mediaWork();
        $s = $w['ids'][0];
        $this->mediaSatisfied($w);
        $this->reusePassed($w);
        $auth = $this->reuseRecord($w, 'authorize');
        $this->mediaRecord($w, 'attempt', $this->mediaFields($w, 'attempt', 'failed'));
        $this->rejectValidation(fn () => $this->reuseRecord($w, 'acknowledge', [], true));
        $this->mediaSatisfied($w);
        $this->rejectValidation(fn () => $this->reuseRecord($w, 'acknowledge', [], true));
        $this->assertSame('pending', DB::table('reuse_authorizations')->where('id', $auth)->value('state'));
        $this->reuseRecord($w, 'withdraw');
        $this->reuseRecord($w, 'authorize');
        $this->reuseRecord($w, 'acknowledge', [], true);
        $this->assertDatabaseCount('reuse_return_items', 1);
    }

    public function test_unsupported_configuration_and_replacement_fields_do_not_create_work(): void
    {
        $w = $this->mediaWork();
        foreach (['parent_id' => null, 'owner_id' => [], 'storage' => [], 'replacement_id' => null, 'origin' => []] as $key => $value) {
            $this->rejectValidation(fn () => $this->reuseRecord($w, 'work', [$key => $value]));
        }
        $this->assertDatabaseCount('refurbishment_records', 0);
        $this->reuseRecord($w, 'work', ['configuration' => 'UNKNOWN']);
        $this->rejectValidation(fn () => $this->reuseRecord($w, 'test'));
        $this->assertDatabaseCount('refurbishment_records', 1);
    }

    public function test_database_rejects_unbacked_history_controls_and_post_release_mutations(): void
    {
        $w = $this->mediaWork();
        $s = $w['ids'][0];
        $this->mediaSatisfied($w);
        $this->reuseRecord($w);
        $this->rejectDatabase(function () use ($w, $s) {
            $r = (array) DB::table('refurbishment_records')->first();
            $r['predecessor_id'] = $r['id'];
            $r['id'] = Str::uuid()->toString();
            $r['version']++;
            $r['context'] = json_encode(app(Context::class)->snapshot($s, $w['receiver']->id));
            $r['command_id'] = AdminMutation::command($w['receiver'], 'refurbishment_work:'.$s, Str::uuid()->toString(), 'synthetic_private', $r['id']);
            $event = (array) DB::table('domain_events')->where('id', $r['event_id'])->first();
            $r['event_id'] = Str::uuid()->toString();
            $event['id'] = $r['event_id'];
            $event['command_id'] = $r['command_id'];
            $event['aggregate_version'] = $r['version'];
            DB::table('domain_events')->insert($event);
            DB::table('refurbishment_records')->insert($r);
            // All rows look valid individually; omit the required projection and force deferred evaluation.
        }, true);
        $this->reuseRecord($w, 'test');
        $this->reuseRecord($w, 'authorize');
        $this->reuseRecord($w, 'acknowledge', [], true);
        $this->rejectDatabase(fn () => DB::table('reuse_return_items')->where('subject_id', $s)->delete());
        $this->rejectDatabase(fn () => DB::table('items')->where('subject_id', $s)->update(['processing_state' => 'assessed']));
        $this->rejectDatabase(fn () => DB::table('custody_current')->where('subject_id', $s)->increment('version'));
        $this->assertSame('released', DB::table('items')->where('subject_id', $s)->value('processing_state'));
    }

    private function rejectValidation(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected safe validation rejection');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    private function rejectDatabase(callable $action, bool $deferred = false): void
    {
        DB::beginTransaction();
        $inserted = false;
        try {
            $action();
            $inserted = true;
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            $this->fail('Invalid direct mutation passed the constraint enforcement boundary');
        } catch (QueryException $e) {
            if ($deferred) {
                $this->assertTrue($inserted, 'The mutation must pass insertion and fail at forced deferred evaluation.');
            }
            $this->assertTrue(true);
        } finally {
            DB::rollBack();
        }
    }

    public function test_supported_http_controls_complete_work_test_and_owner_acknowledgment_privately(): void
    {
        $w = $this->mediaWork();
        $s = $w['ids'][0];
        $this->mediaSatisfied($w);
        $this->receiptSignIn($w['receiver'])->get(route('refurbishment.index'))->assertOk()->assertSee('Refurbishment / Owner return');
        foreach (['work', 'test', 'authorize'] as $kind) {
            $this->get(route('refurbishment.show', ['subject' => $s, 'action' => $kind]))->assertOk()->assertSee(Context::LABELS[$kind]);
            $this->post(route('refurbishment.store', [$s, $kind]), $this->reuseFields($w, $kind))->assertRedirect(route('refurbishment.show', $s))->assertSessionHasNoErrors();
            $this->handoverBoundary();
        }
        $this->receiptSignIn($w['owner'])->get(route('refurbishment.show', ['subject' => $s, 'action' => 'acknowledge']))->assertOk()->assertSee('Pending owner acknowledgment')->assertSee('name="received[]"', false)->assertDontSee('Private synthetic work/result/evidence');
        $this->post(route('refurbishment.store', [$s, 'acknowledge']), $this->reuseFields($w, 'acknowledge', true))->assertRedirect()->assertSessionHasNoErrors();
        $this->handoverBoundary();
        $this->get(route('refurbishment.show', $s))->assertOk()->assertSee('Complete return acknowledged')->assertSee('Acknowledged')->assertDontSee('Private synthetic work/result/evidence');
        $this->receiptSignIn($w['receiver'])->get(route('refurbishment.show', $s))->assertNotFound();
        $this->get(route('refurbishment.index'))->assertOk()->assertDontSee(DB::table('subjects')->where('id', $s)->value('public_code'));
    }

    public function test_http_validation_reselects_private_input_and_associates_focus_errors(): void
    {
        $w = $this->mediaWork();
        $s = $w['ids'][0];
        $this->reuseRecord($w);
        $this->receiptSignIn($w['receiver']);
        $f = $this->reuseFields($w, 'test');
        unset($f['answers']['detection']);
        $this->post(route('refurbishment.store', [$s, 'test']), $f)->assertRedirect(route('refurbishment.show', ['subject' => $s, 'action' => 'test']))->assertSessionHasErrors('answers.detection');
        $old = session()->getOldInput();
        $this->assertSame(['idempotency_key'], array_keys($old));
        $this->assertArrayNotHasKey('expected_context', $old);
        $this->assertArrayNotHasKey('reason', $old);
        // The test client must carry the session cookie through the actual feedback redirect.
        $this->withCookie(config('session.cookie'), session()->getId())->get(route('refurbishment.show', ['subject' => $s, 'action' => 'test']))->assertOk()->assertSee('data-error-summary', false)->assertSee('aria-invalid="true"', false)->assertSee('answers_detection-error', false)->assertDontSee('value="Private synthetic work/result/evidence', false);
    }

    public function test_direct_derived_work_independent_movement_and_staff_impersonation_are_rejected(): void
    {
        $w = $this->reuseParent();
        $medium = DB::table('media_origins')->value('subject_id');
        $this->rejectDatabase(fn () => DB::table('custody_current')->where('subject_id', $medium)->increment('version'));
        $this->receiptSignIn($w['receiver'])->get(route('refurbishment.show', $medium))->assertNotFound();
        $this->receiptSignIn($w['admin'])->get(route('refurbishment.index'))->assertForbidden();
        $this->get(route('refurbishment.show', $w['ids'][0]))->assertForbidden();
    }

    public function test_unknown_unsupported_and_destroyed_media_never_clear_parent_or_drive_reuse(): void
    {
        $w = $this->inventoryWork('UNKNOWN');
        $this->inventoryRecord($w, 'attest');
        $this->reusePassed($w);
        $this->rejectValidation(fn () => $this->reuseRecord($w, 'authorize'));
        $other = $this->inventoryWork();
        $this->identifyMedium($other, 'other_nonvolatile');
        $this->inventoryRecord($other, 'attest');
        $this->reusePassed($other);
        $this->rejectValidation(fn () => $this->reuseRecord($other, 'authorize'));
        $drive = $this->mediaWork();
        $attempt = $this->mediaFields($drive);
        $attempt['technique'] = 'media_shredding';
        unset($attempt['tool_version']);
        $this->mediaSatisfied($drive, $attempt);
        $this->reusePassed($drive);
        $this->assertSame('satisfied', app(\App\Support\Sanitization\Context::class)->state($drive['ids'][0]));
        $this->rejectValidation(fn () => $this->reuseRecord($drive, 'authorize'));
    }

    public function test_new_decision_alone_does_not_replace_required_fresh_inspection_after_failure(): void
    {
        $w = $this->mediaWork();
        $this->reuseRecord($w, 'work', ['outcome' => 'failed']);
        app(InspectItems::class)->decide($w['receiver'], $w['ids'][0], $this->decisionFields($w['ids'][0]));
        $this->handoverBoundary();
        $this->rejectValidation(fn () => $this->reuseRecord($w));
    }

    public function test_recognized_standalone_identity_returns_with_parent_without_new_origin_or_receipt(): void
    {
        $w = $this->inventoryWork();
        $drive = $this->standaloneAtParentFacility($w);
        $before = (array) DB::table('items')->where('subject_id', $drive)->first();
        $this->inventoryRecord($w, 'link', ['existing_code' => DB::table('subjects')->where('id', $drive)->value('public_code')]);
        $this->inventoryRecord($w, 'attest');
        $this->mediaSatisfied($this->mediumWork($w, $drive));
        $this->rejectValidation(fn () => $this->reuseRecord($this->mediumWork($w, $drive)));
        $this->reusePassed($w);
        $this->reuseRecord($w, 'authorize');
        $this->reuseRecord($w, 'acknowledge', [], true);
        $after = (array) DB::table('items')->where('subject_id', $drive)->first();
        unset($before['processing_state'],$after['processing_state']);
        $this->assertSame($before, $after);
        $this->assertDatabaseMissing('media_origins', ['subject_id' => $drive]);
        $this->assertDatabaseCount('first_receipts', 2);
        $this->assertDatabaseCount('handover_receipts', 2);
        $this->assertDatabaseCount('reuse_return_items', 2);
    }

    public function test_scope_restore_does_not_revive_authorization_and_evidence_remains_private(): void
    {
        Storage::fake('directory_evidence');
        $w = $this->mediaWork();
        $s = $w['ids'][0];
        $this->mediaSatisfied($w);
        $fields = $this->reuseFields($w);
        $fields['evidence_kind'] = 'attachment';
        unset($fields['reference_issuer'],$fields['reference_code'],$fields['reference_attested']);
        $id = app(RefurbishItems::class)->record($w['receiver'], $s, 'work', $fields, UploadedFile::fake()->image('private.jpg'));
        $this->handoverBoundary();
        $url = route('refurbishment.evidence', $id);
        $this->receiptSignIn($w['receiver'])->get($url)->assertOk();
        $this->reuseRecord($w, 'test');
        $this->reuseRecord($w, 'authorize');
        $this->receiptSignIn($w['owner'])->get($url)->assertForbidden();
        DB::table('memberships')->where('user_id', $w['receiver']->id)->update(['active' => false, 'version' => DB::raw('version+1')]);
        $this->receiptSignIn($w['receiver']->fresh())->get($url)->assertRedirect();
        $this->rejectValidation(fn () => $this->reuseRecord($w, 'acknowledge', [], true));
        DB::table('memberships')->where('user_id', $w['receiver']->id)->update(['active' => true, 'version' => DB::raw('version+1')]);
        $this->rejectValidation(fn () => $this->reuseRecord($w, 'acknowledge', [], true));
        $this->assertDatabaseCount('reuse_return_items', 0);
    }

    public function test_dependency_change_after_acknowledgment_insert_fails_at_deferred_boundary(): void
    {
        $w = $this->mediaWork();
        $s = $w['ids'][0];
        $this->mediaSatisfied($w);
        $this->reusePassed($w);
        $this->reuseRecord($w, 'authorize');
        $before = DB::table('custody_current')->get()->toJson();
        $f = $this->reuseFields($w, 'acknowledge', true);
        $this->rejectDatabase(function () use ($w, $s, $f) {
            app(RefurbishItems::class)->record($w['owner'], $s, 'acknowledge', $f);
            DB::table('memberships')->where('user_id', $w['receiver']->id)->increment('version');
        }, true);
        $this->assertSame($before, DB::table('custody_current')->get()->toJson());
        $this->assertDatabaseCount('reuse_return_items', 0);
    }

    public function test_work_test_safety_uncertainty_is_a_retained_restriction_not_an_ordinary_reassessment_override(): void
    {
        $w = $this->mediaWork();
        $s = $w['ids'][0];
        $this->reuseRecord($w);
        $f = $this->reuseFields($w, 'test');
        $f['answers']['safety'] = 'UNKNOWN';
        $this->reuseRecord($w, 'test', $f);
        app(InspectItems::class)->inspect($w['receiver'], $s, $this->inspectionFields($s));
        app(InspectItems::class)->decide($w['receiver'], $s, $this->decisionFields($s));
        $this->handoverBoundary();
        $this->rejectValidation(fn () => $this->reuseRecord($w));
    }

    public function test_stale_authorization_can_be_withdrawn_after_capability_loss_without_bypassing_release_gate(): void
    {
        $w = $this->mediaWork();
        $s = $w['ids'][0];
        $this->mediaSatisfied($w);
        $this->reusePassed($w);
        $auth = $this->reuseRecord($w, 'authorize');
        DB::table('place_categories')->where('place_id', $w['facility'])->where('category_id', DB::table('items')->where('subject_id', $s)->value('category_id'))->where('subtype', 'ssd')->delete();
        $this->rejectValidation(fn () => $this->reuseRecord($w, 'acknowledge', [], true));
        $this->reuseRecord($w, 'withdraw');
        $this->assertSame('withdrawn', DB::table('reuse_authorizations')->where('id', $auth)->value('state'));
        $this->assertDatabaseCount('reuse_return_items', 0);
    }

    public function test_recipient_security_changes_invalidate_authorization_without_revival(): void
    {
        $w = $this->mediaWork();
        $this->mediaSatisfied($w);
        $this->reusePassed($w);
        $this->reuseRecord($w, 'authorize');
        DB::table('users')->where('id', $w['owner']->id)->increment('session_version');
        $w['owner'] = $w['owner']->fresh();
        $this->rejectValidation(fn () => $this->reuseRecord($w, 'acknowledge', [], true));
        DB::table('memberships')->where('user_id', $w['owner']->id)->increment('version');
        $this->rejectValidation(fn () => $this->reuseRecord($w, 'acknowledge', [], true));
        $this->assertDatabaseCount('reuse_return_items', 0);
    }
}
