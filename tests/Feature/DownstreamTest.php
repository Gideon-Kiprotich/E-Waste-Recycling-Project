<?php

namespace Tests\Feature;

use App\Actions\DismantleBatches;
use App\Actions\ManageOutputProfiles;
use App\Actions\MoveBatchOutputs;
use App\Support\Downstream\Context;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\DownstreamFixtures;
use Tests\TestCase;

class DownstreamTest extends TestCase
{
    use DatabaseTransactions, DownstreamFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::table('migrations')->where('migration', '2026_10_19_000004_refine_downstream_boundaries')->exists() && ! DB::table('migrations')->where('migration', '2026_10_19_000005_validate_downstream_origins')->exists()) {
            (require database_path('migrations/2026_10_19_000005_validate_downstream_origins.php'))->up();
        }
        if (! DB::table('migrations')->where('migration', '2026_10_19_000004_refine_downstream_boundaries')->exists()) {
            (require database_path('migrations/2026_10_19_000004_refine_downstream_boundaries.php'))->up();
        }
    }

    public function test_partial_material_journey_deducts_once_and_closes_only_after_completion(): void
    {
        $w = $this->downstreamWork();
        $h = $this->shipmentDraft($w);
        $this->assertEquals(10, app(Context::class)->stock($w['output'])['reservable']);
        $this->downstreamRecord($w, 'reserve', $h);
        $this->downstreamRecord($w, 'dispatch', $h, overrides: ['kg' => '4.001']);
        $this->assertEquals('5.999', app(Context::class)->stock($w['output'])['reserved']);
        $this->downstreamRecord($w, 'accept', $h, true, ['kg' => '1.001']);
        $this->downstreamRecord($w, 'complete', $h, true, ['kg' => '1.001']);
        $this->assertEquals('5.999', app(Context::class)->stock($w['output'])['accounted']);
        $this->assertFalse(DB::selectOne('SELECT downstream_closable(?) AS ok', [$w['batch']])->ok);
        $this->downstreamRecord($w, 'accept', $h, true, ['kg' => '3.000']);
        $this->downstreamRecord($w, 'complete', $h, true, ['kg' => '3.000']);
        $this->downstreamRecord($w, 'dispatch', $h, overrides: ['kg' => '5.999']);
        $this->downstreamRecord($w, 'accept', $h, true, ['kg' => '5.999']);
        $this->downstreamRecord($w, 'complete', $h, true, ['kg' => '5.999']);
        $this->assertTrue(DB::selectOne('SELECT downstream_closable(?) AS ok', [$w['batch']])->ok);
        $this->downstreamRecord($w, 'close');
        $this->assertEquals(0, app(Context::class)->stock($w['output'])['accounted']);
    }

    public function test_canonical_receipt_preserves_identity_and_requires_successor_review(): void
    {
        $w = $this->downstreamWork(true);
        $identity = DB::table('items')->where('subject_id', $w['output'])->first();
        $h = $this->shipmentDraft($w);
        $this->downstreamRecord($w, 'reserve', $h);
        $this->downstreamRecord($w, 'dispatch', $h);
        $this->assertSame('review_required', app(\App\Support\Sanitization\Context::class)->state($w['output']));
        $this->downstreamRecord($w, 'accept', $h, true);
        $this->assertEquals($identity, DB::table('items')->where('subject_id', $w['output'])->first());
        $this->downstreamAuthorize($w, $w['recipient']);
        $r = array_replace($this->mediumWork($w, $w['output']), ['receiver' => $w['recipient']]);
        $this->mediaRecord($r, 'revalidation');
        $this->assertSame('satisfied', app(\App\Support\Sanitization\Context::class)->state($w['output']));
        $this->downstreamRecord($w, 'complete', $h, true);
    }

    public function test_retry_first_returns_original_after_later_activity_and_altered_payload_conflicts(): void
    {
        $w = $this->downstreamWork();
        $f = $this->downstreamFields($w, 'draft');
        $a = app(MoveBatchOutputs::class);
        $id = $a->record($w['receiver'], $w['batch'], 'draft', $f);
        $this->handoverBoundary();
        $h = DB::table('downstream_records')->where('id', $id)->value('shipment_id');
        $this->downstreamRecord($w, 'reserve', $h);
        $this->downstreamRecord($w, 'dispatch', $h);
        $this->downstreamRecord($w, 'accept', $h, true);
        $this->downstreamRecord($w, 'complete', $h, true);
        $this->downstreamRecord($w, 'close');
        $before = $this->counts();
        $this->assertSame($id, $a->record($w['receiver'], $w['batch'], 'draft', $f));
        $this->assertSame($before, $this->counts());
        $this->denied(fn () => $a->record($w['receiver'], $w['batch'], 'draft', array_replace($f, ['kg' => '9.999'])));
    }

    public function test_source_and_downstream_loss_are_distinct_and_never_deduct_twice(): void
    {
        $w = $this->downstreamWork();
        $h = $this->shipmentDraft($w, ['kg' => '6.000']);
        $this->downstreamRecord($w, 'reserve', $h, overrides: ['kg' => '6.000']);
        $this->downstreamRecord($w, 'dispatch', $h, overrides: ['kg' => '4.000']);
        $this->downstreamRecord($w, 'source_loss', overrides: ['kg' => '1.000']);
        $this->downstreamRecord($w, 'downstream_loss', $h, true, ['kg' => '1.000']);
        $st = app(Context::class)->stock($w['output']);
        $this->assertEquals(5, $st['accounted']);
        $this->assertEquals(2, $st['reserved']);
        $this->assertEquals(3, $st['reservable']);
        $this->assertEquals(1, $st['source_loss']);
        $this->assertEquals(1, $st['downstream_loss']);
        $this->assertDatabaseCount('downstream_exceptions', 2);
        $this->denied(fn () => $this->downstreamRecord($w, 'close'));
    }

    public function test_withdrawal_releases_only_undispatched_quantity_and_oversubscription_is_rejected(): void
    {
        $w = $this->downstreamWork();
        $h = $this->shipmentDraft($w);
        $second = $this->shipmentDraft($w);
        $this->downstreamRecord($w, 'reserve', $h);
        $this->denied(fn () => $this->downstreamRecord($w, 'reserve', $second));
        $this->downstreamRecord($w, 'dispatch', $h, overrides: ['kg' => '4.001']);
        $this->denied(fn () => $this->downstreamRecord($w, 'withdraw', $h));
        $this->downstreamRecord($w, 'withdraw', $h, overrides: ['kg' => '5.999']);
        $this->assertEquals('5.999', app(Context::class)->stock($w['output'])['reservable']);
        $this->denied(fn () => $this->downstreamRecord($w, 'dispatch', $h, overrides: ['kg' => '0.001']));
    }

    public function test_refusal_shortage_and_damaged_acceptance_partition_quantities_without_restoring_stock(): void
    {
        $w = $this->downstreamWork();
        $h = $this->shipmentDraft($w);
        $this->downstreamRecord($w, 'reserve', $h);
        $dispatch = $this->downstreamRecord($w, 'dispatch', $h);
        $this->downstreamRecord($w, 'accept', $h, true, ['kg' => '2.000', 'condition' => 'damaged']);
        $this->downstreamRecord($w, 'refuse', $h, true, ['kg' => '3.000']);
        $this->downstreamRecord($w, 'shortage', $h, true, ['kg' => '5.000']);
        $this->assertEquals(0, DB::selectOne('SELECT downstream_remaining(?) AS n', [$dispatch])->n);
        $this->assertEquals(0, app(Context::class)->stock($w['output'])['accounted']);
        $this->assertEquals(2, app(Context::class)->stock($w['output'])['accepted']);
        $this->assertDatabaseCount('downstream_exceptions', 3);
        $this->denied(fn () => $this->downstreamRecord($w, 'accept', $h, true, ['kg' => '0.001']));
        $this->denied(fn () => $this->downstreamRecord($w, 'close'));
    }

    public function test_stale_profile_requires_fresh_shipment_and_cannot_be_revived_by_completed_retry(): void
    {
        $w = $this->downstreamWork();
        $h = $this->shipmentDraft($w);
        $this->downstreamRecord($w, 'reserve', $h);
        $f = $this->profileFields($w);
        app(ManageOutputProfiles::class)->record($w['admin'], $w['profile'], array_replace($f, ['approved' => '0']));
        $this->handoverBoundary();
        $this->denied(fn () => $this->downstreamRecord($w, 'dispatch', $h));
        $w['profile_record'] = app(ManageOutputProfiles::class)->record($w['admin'], $w['profile'], $this->profileFields($w));
        $this->handoverBoundary();
        $this->denied(fn () => $this->downstreamRecord($w, 'dispatch', $h));
        $this->downstreamRecord($w, 'withdraw', $h);
        $fresh = $this->shipmentDraft($w);
        $this->downstreamRecord($w, 'reserve', $fresh);
        $this->downstreamRecord($w, 'dispatch', $fresh);
    }

    public function test_measurement_correction_requires_withdrawal_then_freezes_after_dispatch(): void
    {
        $w = $this->downstreamWork();
        $h = $this->shipmentDraft($w);
        $this->downstreamRecord($w, 'reserve', $h);
        $measurement = $this->batchFields($w, 'measurement');
        $measurement['target_id'] = $w['output'];
        $measurement['previous_measurement_id'] = DB::table('batch_outputs')->where('subject_id', $w['output'])->value('measurement_record_id');
        $measurement['net_kg'] = '10.000';
        $this->denied(fn () => app(DismantleBatches::class)->record($w['receiver'], $w['batch'], 'measurement', $measurement));
        $this->downstreamRecord($w, 'withdraw', $h);
        app(DismantleBatches::class)->record($w['receiver'], $w['batch'], 'measurement', $measurement);
        $this->handoverBoundary();
        $this->batchRecord($w, 'accounted');
        $this->denied(fn () => $this->downstreamRecord($w, 'reserve', $h));
        $fresh = $this->shipmentDraft($w);
        $this->downstreamRecord($w, 'reserve', $fresh);
        $this->downstreamRecord($w, 'dispatch', $fresh);
        $measurement = array_replace($measurement, $this->batchFields($w, 'measurement'), ['target_id' => $w['output'], 'previous_measurement_id' => DB::table('batch_outputs')->where('subject_id', $w['output'])->value('measurement_record_id'), 'net_kg' => '10.000']);
        $this->denied(fn () => app(DismantleBatches::class)->record($w['receiver'], $w['batch'], 'measurement', $measurement));
        $this->downstreamRecord($w, 'measurement_dispute');
        $this->assertTrue(DB::selectOne('SELECT downstream_blocked(?) AS n', [$w['batch']])->n);
    }

    public function test_post_closure_dispute_retains_closure_and_blocks_later_progression(): void
    {
        $w = $this->downstreamWork();
        $h = $this->shipmentDraft($w);
        foreach (['reserve', 'dispatch'] as $kind) {
            $this->downstreamRecord($w, $kind, $h);
        }
        foreach (['accept', 'complete'] as $kind) {
            $this->downstreamRecord($w, $kind, $h, true);
        }
        $closure = $this->downstreamRecord($w, 'close');
        $this->downstreamRecord($w, 'measurement_dispute');
        $this->assertSame($closure, DB::table('downstream_batches')->where('batch_id', $w['batch'])->value('closure_record_id'));
        $this->assertTrue(DB::selectOne('SELECT downstream_blocked(?) AS n', [$w['batch']])->n);
        $this->denied(fn () => $this->downstreamRecord($w, 'close'));
    }

    public function test_protected_fields_and_decimal_errors_do_not_create_records(): void
    {
        $w = $this->downstreamWork();
        foreach (['custody', 'state', 'owner_user_id', 'outcome', 'physical_event_id', 'canonical'] as $field) {
            foreach ([null, []] as $value) {
                $this->denied(fn () => $this->downstreamRecord($w, 'draft', overrides: [$field => $value]));
            }
        }
        foreach (['0', '-1', '0.0001', '1e1', '1000000000.000'] as $value) {
            $this->denied(fn () => $this->shipmentDraft($w, ['kg' => $value]));
        }
        $this->denied(fn () => $this->shipmentDraft($w, ['hazards' => 'UNKNOWN']));
        $this->denied(fn () => $this->shipmentDraft($w, ['attested' => '0']));
        $this->assertDatabaseCount('downstream_records', 0);
    }

    public function test_current_role_scope_and_recipient_privacy_apply_to_pages_and_evidence(): void
    {
        $w = $this->downstreamWork();
        $h = $this->shipmentDraft($w);
        $this->receiptSignIn($w['recipient'])->get(route('downstream.shipment', $h))->assertOk()->assertSee('Named Processor')->assertDontSee($w['owner']->email);
        $this->receiptSignIn($w['recipient'])->get(route('downstream.show', $w['batch']))->assertNotFound();
        $this->receiptSignIn($w['owner'])->get(route('downstream.shipment', $h))->assertForbidden();
        $this->receiptSignIn($w['owner'])->get(route('batches.show', $w['batch']))->assertOk()->assertDontSee('10.000')->assertDontSee('Private movement history');
        $stranger = $this->pointStaff($w['admin'], $w['destination']);
        $this->receiptSignIn($stranger)->get(route('downstream.shipment', $h))->assertNotFound();
        $this->denied(fn () => app(MoveBatchOutputs::class)->record($w['admin'], $w['batch'], 'reserve', $this->downstreamFields($w, 'reserve', $h)));
    }

    public function test_supported_http_forms_complete_one_material_movement_and_preserve_private_error_input(): void
    {
        $w = $this->downstreamWork();
        $this->receiptSignIn($w['receiver'])->get(route('downstream.show', ['batch' => $w['batch'], 'action' => 'draft']))->assertOk()->assertSee('Prepare shipment draft')->assertSee('Actual declared output hazards');
        $this->post(route('downstream.store', [$w['batch'], 'draft']), $this->downstreamFields($w, 'draft'))->assertRedirect();
        $h = DB::table('downstream_shipments')->value('id');
        foreach (['reserve', 'dispatch'] as $kind) {
            $this->get(route('downstream.shipment', ['shipment' => $h, 'action' => $kind]))->assertOk()->assertSee(Context::LABELS[$kind]);
            $this->post(route('downstream.store', [$w['batch'], $kind]), $this->downstreamFields($w, $kind, $h))->assertRedirect(route('downstream.shipment', $h));
            $this->handoverBoundary();
        }
        $this->receiptSignIn($w['recipient']);
        foreach (['accept', 'complete'] as $kind) {
            $this->get(route('downstream.shipment', ['shipment' => $h, 'action' => $kind]))->assertOk()->assertSee(Context::LABELS[$kind]);
            $this->post(route('downstream.store', [$w['batch'], $kind]), $this->downstreamFields($w, $kind, $h, true))->assertRedirect(route('downstream.shipment', $h));
            $this->handoverBoundary();
        }
        $this->receiptSignIn($w['receiver'])->post(route('downstream.store', [$w['batch'], 'close']), $this->downstreamFields($w, 'close'))->assertRedirect(route('downstream.show', $w['batch']));
        $this->handoverBoundary();
        $this->get(route('downstream.show', $w['batch']))->assertOk()->assertSee('Closed');
        $this->receiptSignIn($w['owner'])->get(route('batches.show', $w['batch']))->assertOk()->assertSee('Closed recorded outcomes')->assertDontSee('10.000');
        $f = $this->downstreamFields($w, 'measurement_dispute');
        $f['attested'] = '0';
        $this->receiptSignIn($w['receiver'])->post(route('downstream.store', [$w['batch'], 'measurement_dispute']), $f)->assertSessionHasErrors('attested')->assertSessionMissing('_old_input.reason')->assertSessionMissing('_old_input.reference_code');
        $this->withCookie(config('session.cookie'), session()->getId())->get(route('downstream.show', ['batch' => $w['batch'], 'action' => 'measurement_dispute']))->assertOk()->assertSee('aria-invalid="true"', false)->assertSee('aria-describedby="attested-error"', false);
    }

    public function test_direct_writes_and_forced_deferred_constraints_reject_unbacked_state(): void
    {
        $w = $this->downstreamWork();
        $h = $this->shipmentDraft($w);
        $this->downstreamRecord($w, 'reserve', $h);
        foreach ([fn () => DB::table('downstream_batches')->where('batch_id', $w['batch'])->update(['version' => DB::raw('version+1')]), fn () => DB::table('downstream_records')->delete(), fn () => DB::table('downstream_shipments')->delete(), fn () => DB::table('output_profiles')->delete(), fn () => DB::table('custody_current')->where('subject_id', $w['ids'][0])->update(['version' => DB::raw('version+1')])] as $write) {
            DB::beginTransaction();
            try {
                $write();
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                $this->fail('Direct invalid state must fail at enforcement boundary');
            } catch (QueryException $e) {
                $this->assertNotEmpty($e->errorInfo[0]);
            } finally {
                DB::rollBack();
                DB::statement('SET CONSTRAINTS ALL DEFERRED');
            }
        }
        $this->assertEquals(10, app(Context::class)->stock($w['output'])['reserved']);
    }

    public function test_drive_is_indivisible_failed_assurance_blocks_dispatch_and_new_failure_blocks_completion(): void
    {
        $w = $this->downstreamWork(true);
        $this->denied(fn () => $this->shipmentDraft($w, ['kg' => '0.250']));
        $h = $this->shipmentDraft($w);
        $this->downstreamRecord($w, 'reserve', $h);
        $this->denied(fn () => $this->downstreamRecord($w, 'dispatch', $h, overrides: ['kg' => '0.250']));
        $this->downstreamRecord($w, 'dispatch', $h);
        $this->downstreamRecord($w, 'accept', $h, true);
        $this->denied(fn () => $this->downstreamRecord($w, 'complete', $h, true));
        $this->downstreamAuthorize($w, $w['recipient']);
        $r = array_replace($this->mediumWork($w, $w['output']), ['receiver' => $w['recipient']]);
        $this->mediaRecord($r, 'revalidation');
        $this->mediaRecord($r, 'attempt', $this->mediaFields($r, 'attempt', 'failed'));
        $this->denied(fn () => $this->downstreamRecord($w, 'complete', $h, true));
        $this->assertSame('failed', app(\App\Support\Sanitization\Context::class)->state($w['output']));
    }

    public function test_profile_namespace_is_unique_and_only_admin_can_review_acceptance(): void
    {
        $w = $this->downstreamWork();
        $new = Str::uuid()->toString();
        $f = $this->profileFields($w);
        $f['expected_context'] = app(ManageOutputProfiles::class)->token($new);
        $f['expected_version'] = 0;
        try {
            app(ManageOutputProfiles::class)->record($w['admin'], $new, array_replace($f, ['material_key' => '  SYNTHETIC   SORTED metal  ']));
            $this->fail('Same normalized receiving stream cannot have parallel profiles');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('material_key', $e->errors());
        }
        $this->assertDatabaseCount('output_profiles', 1);
        $this->denied(fn () => app(ManageOutputProfiles::class)->record($w['receiver'], $w['profile'], $this->profileFields($w)));
        $this->denied(fn () => $this->shipmentDraft($w, ['receiver_actor_id' => $w['receiver']->id]));
    }

    public function test_post_closure_negative_assurance_is_retained_as_a_blocking_exception(): void
    {
        $w = $this->downstreamWork(true);
        $h = $this->shipmentDraft($w);
        $this->downstreamRecord($w, 'reserve', $h);
        $this->downstreamRecord($w, 'dispatch', $h);
        $this->downstreamRecord($w, 'accept', $h, true);
        $this->downstreamAuthorize($w, $w['recipient']);
        $r = array_replace($this->mediumWork($w, $w['output']), ['receiver' => $w['recipient']]);
        $this->mediaRecord($r, 'revalidation');
        $this->downstreamRecord($w, 'complete', $h, true);
        $material = DB::table('batch_outputs')->where('batch_id', $w['batch'])->where('type', 'material')->value('subject_id');
        $w2 = array_replace($w, ['output' => $material, 'media' => [], 'profile' => Str::uuid()->toString()]);
        $w2['profile_record'] = app(ManageOutputProfiles::class)->record($w['admin'], $w2['profile'], $this->profileFields($w2));
        $this->handoverBoundary();
        $h2 = $this->shipmentDraft($w2, ['kg' => '9.500']);
        $this->downstreamRecord($w2, 'reserve', $h2, overrides: ['kg' => '9.500']);
        $this->downstreamRecord($w2, 'dispatch', $h2, overrides: ['kg' => '9.500']);
        $this->downstreamRecord($w2, 'accept', $h2, true, ['kg' => '9.500']);
        $this->downstreamRecord($w2, 'complete', $h2, true, ['kg' => '9.500']);
        $closure = $this->downstreamRecord($w, 'close');
        $this->mediaRecord($r, 'attempt', $this->mediaFields($r, 'attempt', 'failed'));
        $this->assertSame($closure, DB::table('downstream_batches')->where('batch_id', $w['batch'])->value('closure_record_id'));
        $this->assertTrue(DB::selectOne('SELECT downstream_blocked(?) AS n', [$w['batch']])->n);
        $this->assertDatabaseHas('downstream_exceptions', ['batch_id' => $w['batch'], 'type' => 'post_closure_assurance_contradiction']);
        $this->assertSame('closed_later_evidence_exception', app(\App\Support\Batches\Context::class)->balance($w['batch'])['status']);
    }

    public function test_profile_setup_and_private_evidence_use_supported_http_controls_and_revoke_access(): void
    {
        $w = $this->downstreamWork();
        $this->receiptSignIn($w['admin'])->get(route('admin.output-profiles.edit', $w['profile']))->assertOk()->assertSee('Record output acceptance review');
        $this->post(route('admin.output-profiles.store', $w['profile']), $this->profileFields($w))->assertRedirect(route('admin.output-profiles.edit', $w['profile']));
        $this->handoverBoundary();
        $w['profile_record'] = DB::table('output_profiles')->where('id', $w['profile'])->value('record_id');
        Storage::fake('directory_evidence');
        $f = $this->downstreamFields($w, 'draft');
        $f['evidence_kind'] = 'attachment';
        unset($f['reference_issuer'],$f['reference_code'],$f['reference_attested']);
        $id = app(MoveBatchOutputs::class)->record($w['receiver'], $w['batch'], 'draft', $f, UploadedFile::fake()->image('synthetic-private.png'));
        $this->handoverBoundary();
        $this->receiptSignIn($w['receiver'])->get(route('downstream.evidence', $id))->assertOk();
        $this->receiptSignIn($w['recipient'])->get(route('downstream.evidence', $id))->assertOk();
        $this->receiptSignIn($w['owner'])->get(route('downstream.evidence', $id))->assertForbidden();
        DB::table('memberships')->where('user_id', $w['recipient']->id)->update(['active' => false, 'version' => DB::raw('version+1')]);
        $this->receiptSignIn($w['recipient']->fresh())->get(route('downstream.evidence', $id))->assertRedirect(route('login'));
    }

    public function test_direct_canonical_draft_cannot_forge_frozen_source_custody_metadata(): void
    {
        $w = $this->downstreamWork(true);
        $h = $this->shipmentDraft($w);
        $row = (array) DB::table('downstream_shipments')->where('id', $h)->first();
        $row['id'] = Str::uuid()->toString();
        $row['created_record_id'] = Str::uuid()->toString();
        $row['source_subject_version']++;
        DB::beginTransaction();
        try {
            DB::table('downstream_shipments')->insert($row);
            $this->fail('Forged source metadata must fail immediately, before deferred origin checks');
        } catch (QueryException $e) {
            $this->assertSame('P1300', $e->errorInfo[0]);
            $this->assertStringContainsString('Canonical shipment origin', $e->getMessage());
        } finally {
            DB::rollBack();
        }
    }

    public function test_damaged_accepted_quantity_cannot_be_advanced_to_ordinary_completion(): void
    {
        $w = $this->downstreamWork();
        $h = $this->shipmentDraft($w);
        $this->downstreamRecord($w, 'reserve', $h);
        $this->downstreamRecord($w, 'dispatch', $h);
        $this->downstreamRecord($w, 'accept', $h, true, ['condition' => 'damaged']);
        $this->expectException(ValidationException::class);
        $this->downstreamRecord($w, 'complete', $h, true);
    }

    public function test_unrelated_accounting_change_does_not_permanently_deadlock_actual_receipt(): void
    {
        $w = $this->downstreamWork();
        $this->batchRecord($w, 'measurement', ['target_id' => $w['output'], 'previous_measurement_id' => DB::table('batch_outputs')->where('subject_id', $w['output'])->value('measurement_record_id'), 'net_kg' => '5.000']);
        $this->batchRecord($w, 'output', ['net_kg' => '5.000', 'material_code' => 'Other characterized metal']);
        $other = DB::table('batch_outputs')->where('batch_id', $w['batch'])->where('subject_id', '<>', $w['output'])->first();
        $this->batchRecord($w, 'accounted');
        $h = $this->shipmentDraft($w, ['kg' => '5.000']);
        $this->downstreamRecord($w, 'reserve', $h, overrides: ['kg' => '5.000']);
        $this->downstreamRecord($w, 'dispatch', $h, overrides: ['kg' => '5.000']);
        $this->batchRecord($w, 'measurement', ['target_id' => $other->subject_id, 'previous_measurement_id' => $other->measurement_record_id, 'net_kg' => '4.999']);
        $this->batchRecord($w, 'accounted');
        $this->assertTrue(DB::selectOne('SELECT downstream_ready(?,?) AS ok', [$w['batch'], $w['receiver']->id])->ok);
        $this->downstreamRecord($w, 'accept', $h, true, ['kg' => '5.000']);
        $this->downstreamRecord($w, 'complete', $h, true, ['kg' => '5.000']);
        $this->assertEquals(0, app(Context::class)->stock($w['output'])['accounted']);
        $this->assertFalse(DB::selectOne('SELECT downstream_closable(?) AS ok', [$w['batch']])->ok);
    }

    public function test_stale_form_has_an_actionable_downstream_reload_link(): void
    {
        $w = $this->downstreamWork();
        $f = $this->downstreamFields($w, 'draft');
        $this->shipmentDraft($w);
        $this->receiptSignIn($w['receiver'])->post(route('downstream.store', [$w['batch'], 'draft']), $f)->assertStatus(409)->assertSee('Reload Downstream movements</a>', false);
    }

    public function test_source_form_excludes_its_own_facility_and_explains_missing_downstream_setup(): void
    {
        $w = $this->downstreamWork();
        $own = Str::uuid()->toString();
        $f = array_replace($this->profileFields($w), ['expected_version' => 0, 'expected_context' => app(ManageOutputProfiles::class)->token($own), 'facility_id' => $w['facility']]);
        $ownRecord = app(ManageOutputProfiles::class)->record($w['admin'], $own, $f);
        $this->handoverBoundary();
        $this->receiptSignIn($w['receiver'])->get(route('downstream.show', ['batch' => $w['batch'], 'action' => 'draft']))->assertOk()->assertSee($w['profile_record'])->assertDontSee($ownRecord);
        app(ManageOutputProfiles::class)->record($w['admin'], $w['profile'], array_replace($this->profileFields($w), ['approved' => '0']));
        $this->handoverBoundary();
        $this->get(route('downstream.show', ['batch' => $w['batch'], 'action' => 'draft']))->assertOk()->assertSee('No eligible reviewed destination profile or named Processor');
    }

    private function counts(): array
    {
        return collect(['downstream_records', 'downstream_shipments', 'commands', 'domain_events', 'attachments', 'subjects', 'custody_current'])->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    }

    private function denied(callable $operation): void
    {
        $before = $this->counts();
        try {
            $operation();
            $this->fail('Unsupported/stale operation must be rejected');
        } catch (ValidationException|HttpException $e) {
            $this->assertSame($before, $this->counts());
        }
    }
}
