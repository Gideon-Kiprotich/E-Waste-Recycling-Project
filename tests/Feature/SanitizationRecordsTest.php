<?php

namespace Tests\Feature;

use App\Actions\InspectItems;
use App\Actions\ManagePointStaff;
use App\Actions\RecordSanitization;
use App\Enums\Role;
use App\Models\User;
use App\Support\Collection\AdminMutation;
use App\Support\Routing\Execution;
use App\Support\Sanitization\Context;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SanitizationFixtures;
use Tests\TestCase;

class SanitizationRecordsTest extends TestCase
{
    use DatabaseTransactions, SanitizationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1]);
    }

    public static function media(): array
    {
        return [['hdd'], ['ssd']];
    }

    #[DataProvider('media')]
    public function test_http_success_requires_three_separate_commands_and_preserves_physical_records(string $media): void
    {
        $w = $this->mediaWork($media);
        $s = $w['ids'][0];
        $before = $this->mediaPreservation();
        $this->receiptSignIn($w['receiver'])->get(route('sanitization.index'))->assertOk()->assertSee('Open media assurance and history');
        $this->get(route('sanitization.show', ['subject' => $s, 'action' => 'attempt']))->assertOk()->assertSee('Actual observation/review time (UTC)')->assertSee('Accountable staff assertions');
        foreach (['attempt' => 'pending_verification', 'verification' => 'pending_validation', 'validation' => 'satisfied'] as $kind => $state) {
            $this->post(route('sanitization.store', ['subject' => $s, 'kind' => $kind]), $this->mediaFields($w, $kind))->assertRedirect(route('sanitization.show', $s))->assertSessionHas('status');
            $this->handoverBoundary();
            $this->assertSame($state, app(Context::class)->state($s));
            $this->assertSame($before, $this->mediaPreservation());
            $this->get(route('sanitization.show', $s))->assertOk()->assertSee(Context::labels()[$state]);
        }
        $this->assertDatabaseCount('sanitization_records', 3);
        $this->assertDatabaseCount('storage_assets', 1);
        $this->assertDatabaseHas('items', ['subject_id' => $s, 'data_status' => 'YES']);
        $this->receiptSignIn($w['owner'])->get(route('sanitization.show', $s))->assertOk()->assertSee('Data treatment satisfied (attested)')->assertDontSee('Synthetic external operator')->assertDontSee('PRIVATE-SYNTHETIC-01')->assertDontSee($w['receiver']->display_name)->assertDontSee('Download private media evidence');
    }

    public function test_verification_and_validation_cannot_be_skipped_or_undone_after_negative_evidence(): void
    {
        $w = $this->mediaWork();
        $this->receiptSignIn($w['receiver']);
        $this->postMedia($w, 'validation')->assertConflict();
        $this->mediaRecord($w);
        $this->postMedia($w, 'validation')->assertConflict();
        $f = $this->mediaFields($w, 'verification', 'unsatisfactory');
        $f['checks']['execution_complete'] = 'NO';
        $this->mediaRecord($w, 'verification', $f);
        $this->postMedia($w, 'validation')->assertConflict();
        $this->postMedia($w, 'verification')->assertConflict();
        $this->assertSame('unsatisfactory', app(Context::class)->state($w['ids'][0]));
        $this->assertDatabaseCount('sanitization_records', 2);
    }

    public function test_completion_metadata_is_required_but_honest_negative_attempts_do_not_need_invented_details(): void
    {
        $w = $this->mediaWork();
        $this->receiptSignIn($w['receiver']);
        foreach (['executor', 'tool', 'tool_version', 'scope', 'procedure', 'evidence_kind'] as $name) {
            $f = $this->mediaFields($w);
            unset($f[$name]);
            if ($name === 'evidence_kind') {
                unset($f['reference_issuer'], $f['reference_code'], $f['reference_attested']);
            }
            $this->postMedia($w, 'attempt', $f)->assertUnprocessable()->assertJsonValidationErrors($name);
        }
        $this->assertDatabaseCount('sanitization_records', 0);
        foreach (['failed', 'incomplete', 'unverifiable'] as $outcome) {
            $f = $this->mediaFields($w, 'attempt', $outcome);
            $f['technique'] = 'unknown';
            foreach (['executor', 'tool', 'tool_version', 'scope', 'procedure', 'evidence_kind', 'reference_issuer', 'reference_code', 'reference_attested'] as $name) {
                unset($f[$name]);
            }
            $this->mediaRecord($w, 'attempt', $f);
            $this->assertSame($outcome, app(Context::class)->state($w['ids'][0]));
        }
        $this->assertDatabaseCount('sanitization_records', 3);
        $rows = DB::table('sanitization_records')->orderBy('version')->get();
        $this->assertNull($rows[0]->previous_attempt_id);
        $this->assertSame($rows[0]->id, $rows[1]->previous_attempt_id);
        $this->assertSame($rows[1]->id, $rows[2]->previous_attempt_id);
        $this->assertNull($rows[2]->executor_ciphertext);
        $this->assertNull($rows[2]->evidence_kind);
    }

    public static function unsupported(): array
    {
        return [['hdd', 'ssd_sanitize_block_erase'], ['ssd', 'hdd_sanitize_overwrite'], ['ssd', 'other_purge'], ['hdd', 'other_clear'], ['ssd', 'other_destroy'], ['ssd', 'unknown']];
    }

    #[DataProvider('unsupported')]
    public function test_unsupported_or_mismatched_techniques_and_certificates_cannot_clear_assurance(string $media, string $technique): void
    {
        $w = $this->mediaWork($media);
        $f = $this->mediaFields($w);
        $f['technique'] = $technique;
        $this->mediaRecord($w, 'attempt', $f);
        $this->receiptSignIn($w['receiver']);
        $this->postMedia($w, 'verification')->assertUnprocessable()->assertJsonValidationErrors('outcome');
        $this->assertSame('pending_verification', app(Context::class)->state($w['ids'][0]));
        $this->mediaRecord($w, 'verification', $this->mediaFields($w, 'verification', 'unverifiable'));
        $this->assertSame('unverifiable', app(Context::class)->state($w['ids'][0]));
    }

    public function test_unknown_checks_block_positive_assurance_and_rejected_validation_requires_new_work(): void
    {
        $w = $this->mediaWork();
        $this->mediaRecord($w);
        $this->receiptSignIn($w['receiver']);
        $f = $this->mediaFields($w, 'verification');
        $f['checks']['identity_scope'] = 'UNKNOWN';
        $this->postMedia($w, 'verification', $f)->assertUnprocessable()->assertJsonValidationErrors('outcome');
        $this->mediaRecord($w, 'verification');
        $f = $this->mediaFields($w, 'validation');
        $f['checks']['risk_acceptable'] = 'NO';
        $this->postMedia($w, 'validation', $f)->assertUnprocessable();
        $f['outcome'] = 'rejected';
        $this->mediaRecord($w, 'validation', $f);
        $this->assertSame('rejected', app(Context::class)->state($w['ids'][0]));
        $this->postMedia($w, 'validation')->assertConflict();
        $this->postMedia($w, 'revalidation')->assertConflict();
        $this->mediaSatisfied($w);
        $this->assertSame('satisfied', app(Context::class)->state($w['ids'][0]));
        $this->assertDatabaseCount('sanitization_records', 6);
    }

    public static function negativeOutcomes(): array
    {
        return [['failed'], ['incomplete'], ['unverifiable']];
    }

    #[DataProvider('negativeOutcomes')]
    public function test_newer_negative_attempt_overrides_older_success_without_rewriting_it(string $outcome): void
    {
        $w = $this->mediaWork();
        $this->mediaSatisfied($w);
        $old = DB::table('sanitization_records')->orderBy('version')->get()->toJson();
        $this->mediaRecord($w, 'attempt', $this->mediaFields($w, 'attempt', $outcome));
        $this->assertSame($outcome, app(Context::class)->state($w['ids'][0]));
        $this->assertSame($old, DB::table('sanitization_records')->where('version', '<=', 3)->orderBy('version')->get()->toJson());
        $this->receiptSignIn($w['receiver']);
        $this->postMedia($w, 'revalidation')->assertConflict();
    }

    #[DataProvider('media')]
    public function test_destruction_is_reported_then_verified_then_validated_and_permanently_non_reusable(string $media): void
    {
        $w = $this->mediaWork($media);
        $before = $this->mediaPreservation();
        $f = $this->mediaFields($w);
        $f['technique'] = 'media_shredding';
        unset($f['tool_version']);
        $this->mediaRecord($w, 'attempt', $f);
        $this->receiptSignIn($w['receiver'])->get(route('sanitization.show', $w['ids'][0]))->assertOk()->assertSee('Destruction reported; execution not yet verified');
        $this->mediaRecord($w, 'verification');
        $this->get(route('sanitization.show', $w['ids'][0]))->assertOk()->assertSee('Destruction execution verified; adequacy validation is separate.');
        $this->mediaRecord($w, 'validation');
        $this->assertSame('satisfied', app(Context::class)->state($w['ids'][0]));
        $this->get(route('sanitization.show', $w['ids'][0]))->assertOk()->assertSee('Destruction adequacy accepted (attested); non-reusable, no disposal recorded.');
        $this->mediaRecord($w);
        $this->postMedia($w, 'verification')->assertUnprocessable();
        $this->assertTrue(DB::table('sanitization_current')->value('destruction_reported'));
        $this->assertSame($before, $this->mediaPreservation());
    }

    public function test_even_incomplete_destruction_retains_concerns_until_supported_destruction_assurance(): void
    {
        $w = $this->mediaWork();
        $f = $this->mediaFields($w, 'attempt', 'incomplete');
        $f['technique'] = 'other_destroy';
        $this->mediaRecord($w, 'attempt', $f);
        $this->mediaRecord($w);
        $this->receiptSignIn($w['receiver']);
        $this->postMedia($w, 'verification')->assertUnprocessable();
        $f = $this->mediaFields($w);
        $f['technique'] = 'media_shredding';
        $this->mediaSatisfied($w, $f);
        $this->assertSame('satisfied', app(Context::class)->state($w['ids'][0]));
        $this->assertTrue(DB::table('sanitization_current')->value('destruction_reported'));
    }

    public function test_reassessment_requires_fresh_decision_and_explicit_revalidation_without_fake_new_attempt(): void
    {
        $w = $this->mediaWork();
        $s = $w['ids'][0];
        $this->mediaSatisfied($w);
        app(InspectItems::class)->inspect($w['receiver'], $s, $this->inspectionFields($s));
        $this->handoverBoundary();
        $this->assertSame('review_required', app(Context::class)->state($s));
        $this->receiptSignIn($w['receiver']);
        $this->postMedia($w, 'revalidation')->assertUnprocessable()->assertJsonValidationErrors('submission');
        app(InspectItems::class)->decide($w['receiver'], $s, $this->decisionFields($s));
        $this->handoverBoundary();
        $this->assertSame('review_required', app(Context::class)->state($s));
        $before = $this->mediaPreservation();
        $this->mediaRecord($w, 'revalidation');
        $this->assertSame('satisfied', app(Context::class)->state($s));
        $this->assertSame(1, DB::table('sanitization_records')->where('kind', 'attempt')->count());
        $this->assertSame($before, $this->mediaPreservation());
        $this->postMedia($w, 'revalidation')->assertConflict();
    }

    public function test_drift_between_stages_requires_fresh_execution_review_and_negative_revalidation_stays_blocked(): void
    {
        $w = $this->mediaWork();
        $s = $w['ids'][0];
        $this->mediaRecord($w);
        $this->mediaRecord($w, 'verification');
        app(InspectItems::class)->decide($w['receiver'], $s, $this->decisionFields($s, 'recycle'));
        $this->handoverBoundary();
        $this->receiptSignIn($w['receiver']);
        $this->postMedia($w, 'validation')->assertConflict();
        $this->mediaRecord($w, 'verification');
        $this->mediaRecord($w, 'validation');
        app(InspectItems::class)->decide($w['receiver'], $s, $this->decisionFields($s));
        $this->handoverBoundary();
        $f = $this->mediaFields($w, 'revalidation', 'rejected');
        $f['checks']['unchanged_control'] = 'UNKNOWN';
        $this->mediaRecord($w, 'revalidation', $f);
        $this->assertSame('rejected', app(Context::class)->state($s));
        $this->postMedia($w, 'revalidation')->assertConflict();
    }

    public function test_authorized_colleague_can_review_and_identical_historical_retries_do_not_restore_old_gate(): void
    {
        $w = $this->mediaWork();
        $f = $this->mediaFields($w);
        $id = $this->mediaRecord($w, 'attempt', $f);
        $colleague = $this->pointStaff($w['admin'], $w['facility']);
        $other = array_replace($w, ['receiver' => $colleague]);
        $this->mediaRecord($other, 'verification');
        $this->mediaRecord($other, 'validation');
        $this->mediaRecord($w, 'attempt', $this->mediaFields($w, 'attempt', 'failed'));
        $this->assertSame($id, app(RecordSanitization::class)->record($w['receiver'], $w['ids'][0], 'attempt', $f));
        $this->assertSame('failed', app(Context::class)->state($w['ids'][0]));
        $this->receiptSignIn($w['receiver']);
        $this->postMedia($w, 'attempt', array_replace($f, ['reason' => 'Changed private reason']))->assertConflict();
        $this->postMedia($w, 'attempt', array_replace($f, ['idempotency_key' => Str::uuid()->toString()]))->assertConflict();
        $this->post(route('sanitization.store', ['subject' => $w['ids'][0], 'kind' => 'attempt']), array_replace($f, ['reason' => 'Changed private reason']))->assertConflict()->assertSee('Reload media assurance and history');
        $this->assertDatabaseCount('sanitization_records', 4);
        $this->assertSame($colleague->id, DB::table('sanitization_records')->where('kind', 'verification')->value('actor_id'));
    }

    public function test_private_evidence_and_safe_milestones_reauthorize_old_links_after_revocation(): void
    {
        Storage::fake('directory_evidence');
        $w = $this->mediaWork();
        $f = $this->mediaFields($w);
        $f['evidence_kind'] = 'attachment';
        unset($f['reference_issuer'], $f['reference_code'], $f['reference_attested']);
        $id = app(RecordSanitization::class)->record($w['receiver'], $w['ids'][0], 'attempt', $f, UploadedFile::fake()->image('private.png'));
        $this->handoverBoundary();
        $other = $this->pointStaff($w['admin'], $this->facility($w['point']));
        foreach ([$w['owner'], $w['collector'], $w['pointActor'], $w['admin'], $other, User::factory()->create()] as $actor) {
            $this->receiptSignIn($actor)->get(route('sanitization.evidence', $id))->assertStatus($actor->role() === Role::Processor ? 404 : 403);
            $this->postJson(route('sanitization.store', ['subject' => $w['ids'][0], 'kind' => 'attempt']), $f)->assertStatus($actor->role() === Role::Processor ? 404 : 403);
            if ($actor->id !== $w['owner']->id) {
                $this->get(route('sanitization.show', $w['ids'][0]))->assertStatus(in_array($actor->role(), [Role::Processor, Role::Consumer], true) ? 404 : 403);
            }
        }
        $this->receiptSignIn($w['receiver'])->get(route('sanitization.evidence', $id))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
        app(ManagePointStaff::class)->save($w['admin'], $w['receiver']->id, ['operation' => 'deactivate', 'expected_version' => $w['receiver']->membership->version, 'reason' => 'Synthetic access revocation', 'idempotency_key' => Str::uuid()->toString()]);
        $this->get(route('sanitization.evidence', $id))->assertRedirect(route('login'));
        $this->assertSame('review_required', app(Context::class)->state($w['ids'][0]));
        $this->assertDatabaseCount('sanitization_records', 1);
    }

    public function test_http_evidence_requires_a_single_file_and_accepts_a_valid_private_upload(): void
    {
        Storage::fake('directory_evidence');
        $w = $this->mediaWork();
        $s = $w['ids'][0];
        $before = $this->mediaPreservation();
        $f = $this->mediaFields($w);
        $this->receiptSignIn($w['receiver'])->postJson(route('sanitization.store', ['subject' => $s, 'kind' => 'attempt']), $f + ['evidence' => 'not an upload'])->assertUnprocessable()->assertJsonValidationErrors('evidence');
        $f['evidence_kind'] = 'attachment';
        unset($f['reference_issuer'], $f['reference_code'], $f['reference_attested']);
        $this->post(route('sanitization.store', ['subject' => $s, 'kind' => 'attempt']), $f + ['evidence' => [UploadedFile::fake()->image('private.png')]])->assertRedirect()->assertSessionHasErrors('evidence');
        $this->assertDatabaseCount('sanitization_records', 0);
        $this->assertSame([], Storage::disk('directory_evidence')->allFiles());
        $this->post(route('sanitization.store', ['subject' => $s, 'kind' => 'attempt']), $f + ['evidence' => UploadedFile::fake()->image('private.png')])->assertRedirect(route('sanitization.show', $s));
        $this->handoverBoundary();
        $this->assertDatabaseCount('sanitization_records', 1);
        $this->assertDatabaseCount('attachments', 1);
        $this->assertSame('pending_verification', app(Context::class)->state($s));
        $this->assertSame($before, $this->mediaPreservation());
    }

    public function test_whole_devices_and_fans_show_explicit_limitation_and_cannot_receive_clearance(): void
    {
        foreach ([['laptop', null, 'laptop-healthy'], ['standalone_part', 'ict_cooling_fan', 'ict_cooling_fan-healthy']] as [$category, $subtype, $case]) {
            $w = $this->inspectionWork($category, $subtype);
            $s = $w['ids'][0];
            app(InspectItems::class)->inspect($w['receiver'], $s, $this->inspectionFields($s, $case));
            app(InspectItems::class)->decide($w['receiver'], $s, $this->decisionFields($s));
            $this->handoverBoundary();
            $this->receiptSignIn($w['receiver'])->get(route('sanitization.show', $s))->assertOk()->assertSee($category === 'laptop' ? 'Media inventory support pending' : 'Fans are outside')->assertDontSee('Data treatment satisfied (attested)');
            $this->postMedia($w, 'attempt')->assertUnprocessable();
            $this->receiptSignIn($w['owner'])->get(route('sanitization.show', $s))->assertOk()->assertDontSee('Data treatment satisfied (attested)');
        }
        $this->assertDatabaseCount('sanitization_records', 0);
    }

    public function test_inspection_hard_holds_are_not_bypassed(): void
    {
        $w = $this->mediaWork();
        $s = $w['ids'][0];
        $f = $this->inspectionFields($s);
        $f['answers']['thermal_issue'] = 'UNKNOWN';
        app(InspectItems::class)->inspect($w['receiver'], $s, $f);
        app(InspectItems::class)->decide($w['receiver'], $s, $this->decisionFields($s, 'hold'));
        $this->handoverBoundary();
        $this->receiptSignIn($w['receiver'])->get(route('sanitization.show', $s))->assertOk()->assertSee('inspection hard restriction remains unresolved');
        $this->postMedia($w, 'attempt')->assertUnprocessable();
        $this->assertDatabaseCount('sanitization_records', 0);
    }

    public function test_live_capability_loss_invalidates_gate_and_refuses_new_records_without_changing_reservations(): void
    {
        $w = $this->mediaWork();
        $this->mediaSatisfied($w);
        $before = $this->mediaPreservation();
        DB::table('place_categories')->where('place_id', $w['facility'])->where('subtype', 'ssd')->delete();
        $this->assertSame('review_required', app(Context::class)->state($w['ids'][0]));
        $this->receiptSignIn($w['receiver'])->get(route('sanitization.show', $w['ids'][0]))->assertOk()->assertSee('lacks the exact category/subtype capability');
        $this->postMedia($w, 'attempt')->assertUnprocessable();
        $this->assertSame($before, $this->mediaPreservation());
        $this->assertDatabaseCount('sanitization_records', 3);
    }

    public function test_sanitization_preserves_already_consumed_pickup_route_and_collection_counts(): void
    {
        $w = $this->inspectionWork();
        $s = $w['ids'][0];
        app(InspectItems::class)->inspect($w['receiver'], $s, $this->inspectionFields($s));
        app(InspectItems::class)->decide($w['receiver'], $s, $this->decisionFields($s));
        $this->handoverBoundary();
        $run = DB::table('route_runs')->value('id');
        $before = $this->mediaPreservation();
        $this->mediaSatisfied($w);
        $this->assertTrue(app(Execution::class)->current($run));
        $this->assertSame($before, $this->mediaPreservation());
    }

    public function test_no_accepted_facility_receipt_means_no_treatment_authority(): void
    {
        $w = $this->handoverWork();
        $this->initiateHandover($w);
        $this->receiptSignIn($w['receiver'])->get(route('sanitization.show', $w['ids'][0]))->assertNotFound();
        $this->assertDatabaseCount('sanitization_records', 0);
    }

    public function test_future_and_pre_receipt_times_are_rejected_without_partial_records(): void
    {
        $w = $this->mediaWork();
        $this->receiptSignIn($w['receiver']);
        foreach ([now('UTC')->addDay(), now('UTC')->subYear()] as $time) {
            $f = $this->mediaFields($w);
            $f['observed_at'] = $time->format('Y-m-d\TH:i');
            $this->postMedia($w, 'attempt', $f)->assertUnprocessable()->assertJsonValidationErrors('observed_at');
        }
        $this->assertDatabaseCount('sanitization_records', 0);
    }

    public function test_safe_flash_error_focus_associations_and_forged_fields(): void
    {
        $w = $this->mediaWork();
        $s = $w['ids'][0];
        $this->mediaRecord($w);
        $f = $this->mediaFields($w, 'verification');
        $f['reason'] = 'Unsubmitted private review text';
        $f['reference_code'] = 'UNSUBMITTED-PRIVATE-REFERENCE';
        unset($f['checks']['identity_scope']);
        $this->receiptSignIn($w['receiver'])->post(route('sanitization.store', ['subject' => $s, 'kind' => 'verification']), $f)->assertRedirect()->assertSessionHasErrors('checks.identity_scope')->assertSessionMissing('_old_input.reason')->assertSessionMissing('_old_input.reference_code')->assertSessionMissing('_old_input.expected_context')->assertSessionHas('_old_input.checks.execution_complete', 'YES');
        $this->withCookie(config('session.cookie'), session()->getId())->get(route('sanitization.show', ['subject' => $s, 'action' => 'verification']))->assertOk()->assertSee('data-error-summary', false)->assertSee('href="#checks_identity_scope"', false)->assertSee('aria-invalid="true"', false)->assertSee('aria-describedby="checks_identity_scope-error"', false)->assertDontSee($f['reason'])->assertDontSee($f['reference_code']);
        foreach (['owner_user_id', 'data_status', 'parent_item_id', 'storage_assets', 'method', 'policy_id', 'state', 'custody_version', 'checks.extra'] as $name) {
            foreach ([null, []] as $value) {
                $this->postMedia($w, 'verification', $this->mediaFields($w, 'verification') + [$name => $value])->assertUnprocessable();
            }
        }
        $this->assertDatabaseCount('sanitization_records', 1);
    }

    public function test_database_rejects_mutation_and_forged_projection_at_actual_deferred_boundary(): void
    {
        $w = $this->mediaWork();
        $s = $w['ids'][0];
        $id = $this->mediaRecord($w);
        $this->rejectDatabase(fn () => DB::table('sanitization_records')->where('id', $id)->update(['outcome' => 'failed']));
        $this->rejectDatabase(fn () => DB::table('sanitization_policies')->update(['checksum' => 'forged']));
        $this->rejectDatabase(fn () => DB::table('sanitization_policies')->insert(['id' => Str::uuid()->toString(), 'version_code' => 'FORGED', 'definition' => '{}', 'checksum' => 'forged']));
        $this->rejectDatabase(fn () => DB::table('sanitization_current')->where('subject_id', $s)->delete());
        $this->rejectDatabase(fn () => DB::table('sanitization_current')->where('subject_id', $s)->update(['version' => 2, 'destruction_reported' => true]));
        $this->rejectDatabase(fn () => DB::table('storage_assets')->where('subject_id', $s)->delete());
        $this->rejectDatabase(fn () => DB::table('items')->where('subject_id', $s)->update(['data_status' => 'NO']));
        $this->assertSame('pending_verification', app(Context::class)->state($s));
        $this->assertSame(1, DB::table('sanitization_current')->value('version'));
    }

    public function test_non_scalar_action_query_cannot_break_the_history_page(): void
    {
        $w = $this->mediaWork();
        $this->receiptSignIn($w['receiver'])->get(route('sanitization.show', ['subject' => $w['ids'][0], 'action' => ['attempt']]))->assertOk()->assertSee('No media treatment or assurance records.');
    }

    public function test_sanitization_commands_and_events_require_records_at_their_enforcement_boundary(): void
    {
        $w = $this->mediaWork();
        $this->rejectDatabase(fn () => AdminMutation::command($w['receiver'], 'sanitization_attempt:'.$w['ids'][0], Str::uuid()->toString(), 'synthetic_digest', Str::uuid()->toString()));
        $this->rejectDatabase(function () use ($w) {
            $command = AdminMutation::command($w['receiver'], 'synthetic_orphan_event', Str::uuid()->toString(), 'synthetic_digest', Str::uuid()->toString());
            DB::table('domain_events')->insert(['id' => Str::uuid()->toString(), 'subject_id' => null, 'aggregate_kind' => 'sanitization', 'aggregate_id' => $w['ids'][0], 'aggregate_version' => 1, 'actor_id' => $w['receiver']->id, 'type' => 'sanitization_attempt', 'occurred_at' => now(), 'recorded_at' => now(), 'command_id' => $command, 'safe_payload' => '{}']);
        });
        $this->assertDatabaseCount('sanitization_records', 0);
    }

    public function test_direct_complete_record_without_its_gate_or_event_fails_when_deferred_checks_run(): void
    {
        $w = $this->mediaWork();
        $id = $this->mediaRecord($w);
        $inserted = false;
        $this->rejectDatabase(function () use ($w, $id, &$inserted) {
            $r = (array) DB::table('sanitization_records')->where('id', $id)->first();
            $r['id'] = Str::uuid()->toString();
            $r['version']++;
            $r['predecessor_id'] = $id;
            $r['previous_attempt_id'] = $id;
            $r['attempt_id'] = $r['id'];
            $r['command_id'] = AdminMutation::command($w['receiver'], 'sanitization_attempt:'.$w['ids'][0], Str::uuid()->toString(), 'synthetic_digest', $r['id']);
            // Existing event FK is deliberately retained: the deferred reciprocal guard must reject it.
            $r['event_id'] = DB::table('assessments')->value('event_id');
            DB::table('sanitization_records')->insert($r);
            $inserted = true;
        });
        $this->assertTrue($inserted, 'Insert must succeed before the deferred reciprocal guard is forced.');
        $this->assertDatabaseCount('sanitization_records', 1);
    }

    public function test_rollback_removes_new_private_evidence_and_no_partial_command_event_or_gate(): void
    {
        Storage::fake('directory_evidence');
        Storage::disk('directory_evidence')->put('retained.txt', 'private synthetic retained evidence');
        $w = $this->mediaWork();
        $before = $this->mediaPreservation();
        $counts = [DB::table('commands')->count(), DB::table('domain_events')->count(), DB::table('attachments')->count()];
        DB::unprepared("CREATE FUNCTION fail_media_fixture() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Synthetic post-file failure'; END; $$; CREATE TRIGGER fail_media_fixture BEFORE INSERT ON sanitization_current FOR EACH ROW EXECUTE FUNCTION fail_media_fixture();");
        $f = $this->mediaFields($w);
        $f['evidence_kind'] = 'attachment';
        unset($f['reference_issuer'], $f['reference_code'], $f['reference_attested']);
        try {
            app(RecordSanitization::class)->record($w['receiver'], $w['ids'][0], 'attempt', $f, UploadedFile::fake()->image('private.png'));
            $this->fail('Injected database failure expected');
        } catch (QueryException) {
        }
        $this->assertSame(['retained.txt'], Storage::disk('directory_evidence')->allFiles());
        $this->assertSame($counts, [DB::table('commands')->count(), DB::table('domain_events')->count(), DB::table('attachments')->count()]);
        $this->assertSame($before, $this->mediaPreservation());
        $this->assertDatabaseCount('sanitization_records', 0);
        $this->assertDatabaseCount('sanitization_current', 0);
    }

    private function postMedia(array $w, string $kind, ?array $fields = null)
    {
        return $this->postJson(route('sanitization.store', ['subject' => $w['ids'][0], 'kind' => $kind]), $fields ?? $this->mediaFields($w, $kind));
    }

    private function rejectDatabase(callable $mutation): void
    {
        DB::beginTransaction();
        try {
            $mutation();
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            $this->fail('Expected database constraint rejection');
        } catch (QueryException) {
            $this->assertTrue(true);
        } finally {
            DB::rollBack();
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
        }
    }
}
