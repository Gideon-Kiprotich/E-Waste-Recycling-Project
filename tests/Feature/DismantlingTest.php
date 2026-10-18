<?php

namespace Tests\Feature;

use App\Actions\AuthorizeBatchMedia;
use App\Actions\CustodyHandovers;
use App\Actions\DismantleBatches;
use App\Actions\InspectItems;
use App\Actions\InventoryMedia;
use App\Actions\RecordSanitization;
use App\Actions\RecordVisit;
use App\Models\User;
use App\Support\Batches\Context;
use App\Support\Batches\Mass;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BatchFixtures;
use Tests\TestCase;

class DismantlingTest extends TestCase
{
    use BatchFixtures, DatabaseTransactions;

    public function test_two_canonical_drives_detach_once_without_changing_identity_or_custody(): void
    {
        $w = $this->batchWork(2);
        $preserved = [];
        foreach (['items', 'storage_assets', 'custody_current', 'first_receipts', 'handover_receipts', 'media_origins', 'media_inventory_entries', 'assessments', 'decisions', 'route_consumptions'] as $table) {
            $preserved[$table] = DB::table($table)->get()->sortBy(fn ($r) => json_encode($r))->values()->toJson();
        }
        $this->batchRecord($w);
        $this->assertDatabaseCount('batch_members', 0);
        $this->assertDatabaseCount('batch_detachments', 0);
        $this->assertSame('assessed', DB::table('items')->where('subject_id', $w['ids'][0])->value('processing_state'));
        $fields = $this->batchFields($w, 'commit');
        $id = app(DismantleBatches::class)->record($w['receiver'], $w['batch'], 'commit', $fields);
        $this->handoverBoundary();
        $this->assertSame($id, app(DismantleBatches::class)->record($w['receiver'], $w['batch'], 'commit', $fields));
        $this->assertDatabaseCount('batch_members', 1);
        $this->assertDatabaseCount('batch_detachments', 2);
        $this->assertDatabaseCount('batch_outputs', 2);
        $this->assertSame('dismantled', DB::table('items')->where('subject_id', $w['ids'][0])->value('processing_state'));
        foreach ($preserved as $table => $rows) {
            if ($table === 'items') {
                $original = json_decode($rows, true);
                foreach ($original as &$row) {
                    if ($row['subject_id'] === $w['ids'][0]) {
                        $row['processing_state'] = 'dismantled';
                    }
                }
                unset($row);
                $this->assertSame($original, json_decode(DB::table('items')->get()->sortBy(fn ($r) => json_encode($r))->values()->toJson(), true));
            } else {
                $this->assertSame($rows, DB::table($table)->get()->sortBy(fn ($r) => json_encode($r))->values()->toJson(), $table);
            }
        }
        $this->assertSame($w['media'], DB::table('batch_outputs')->orderBy('material_code')->get()->sortBy(fn ($o) => array_search($o->subject_id, $w['media']))->pluck('subject_id')->values()->all());
        $balance = app(Context::class)->balance($w['batch']);
        $this->assertEquals(10, $balance['input_kg']);
        $this->assertSame(2, $balance['unmeasured']);
        $this->assertFalse($balance['recycling_complete']);
        $this->assertFalse($balance['future_dispatch_enabled']);
    }

    public function test_empty_inventory_and_basic_fan_are_supported_without_inventing_storage(): void
    {
        foreach ([false, true] as $fan) {
            $w = $this->batchWork(0, $fan);
            $this->batchRecord($w);
            $this->batchRecord($w, 'commit');
            $this->assertSame(0, DB::table('batch_detachments')->where('batch_id', $w['batch'])->count());
            $this->assertSame('pending_accounting', app(Context::class)->balance($w['batch'])['status']);
        }
    }

    public function test_missing_actual_work_and_missing_or_extra_medium_fail_atomically(): void
    {
        $w = $this->batchWork(2);
        $this->batchRecord($w);
        $f = $this->batchFields($w, 'commit');
        foreach ([['checks' => ['actual_dismantling' => 'NO', 'all_media_detached' => 'YES', 'identity_safety' => 'YES']], ['detached' => [$w['media'][0]]], ['detached' => array_merge($w['media'], [Str::uuid()->toString()])], ['net_kg' => null], ['unit' => 'g']] as $change) {
            $before = $this->counts();
            try {
                app(DismantleBatches::class)->record($w['receiver'], $w['batch'], 'commit', array_replace($f, $change));
                $this->fail('Incomplete actual-work/media/mass assertion must fail');
            } catch (ValidationException) {
                $this->assertSame($before, $this->counts());
            }
        }
        $this->assertDatabaseCount('batch_members', 0);
        $this->assertDatabaseCount('batch_detachments', 0);
    }

    public function test_unknown_unsupported_disputed_inventory_and_non_recycle_decision_block(): void
    {
        foreach (['unknown', 'unsupported', 'disputed', 'refurbish'] as $case) {
            $w = $this->batchWork(1);
            if ($case === 'unknown') {
                $this->inventoryRecord($w, 'unresolved');
            } elseif ($case === 'unsupported') {
                $this->identifyMedium($w, 'other_nonvolatile');
                $this->inventoryRecord($w, 'attest');
            } elseif ($case === 'disputed') {
                $entry = DB::table('media_inventory_entries')->where('parent_id', $w['ids'][0])->first();
                $this->inventoryRecord($w, 'correct', ['entry_id' => $entry->id, 'entry_state' => 'disputed']);
            } else {
                app(InspectItems::class)->decide($w['receiver'], $w['ids'][0], $this->decisionFields($w['ids'][0], 'refurbish'));
            }
            $this->batchRecord($w);
            $before = $this->counts();
            try {
                $this->batchRecord($w, 'commit');
                $this->fail($case.' must block ordinary dismantling');
            } catch (ValidationException) {
                $this->assertSame($before, $this->counts());
            }
        }
    }

    public function test_standalone_drive_and_claimed_child_cannot_be_intact_batch_inputs(): void
    {
        $w = $this->mediaWork();
        $w['batch'] = Str::uuid()->toString();
        $w['media'] = [];
        app(InspectItems::class)->decide($w['receiver'], $w['ids'][0], $this->decisionFields($w['ids'][0], 'recycle'));
        $this->batchRecord($w);
        $this->expectException(ValidationException::class);
        $this->batchRecord($w, 'commit');
    }

    public function test_pending_outputs_corrections_and_completeness_do_not_duplicate_stock(): void
    {
        $w = $this->batchWork();
        $this->batchRecord($w);
        $this->batchRecord($w, 'commit');
        $this->batchRecord($w, 'output', ['measure_mode' => 'unknown', 'net_kg' => null, 'measurement_source' => null]);
        $this->assertSame(2, app(Context::class)->balance($w['batch'])['unmeasured']);
        $this->batchRecord($w, 'measurement');
        $lot = DB::table('batch_outputs')->where('batch_id', $w['batch'])->where('type', 'material')->first();
        $this->batchRecord($w, 'measurement', ['target_id' => $lot->subject_id, 'previous_measurement_id' => null, 'net_kg' => '9.500']);
        $this->assertSame('pending_accounting', app(Context::class)->balance($w['batch'])['status']);
        $this->batchRecord($w, 'accounted');
        $this->assertSame('reconciled_on_site', app(Context::class)->balance($w['batch'])['status']);
        $before = DB::table('batch_outputs')->count();
        $original = DB::table('batch_outputs')->where('subject_id', $lot->subject_id)->value('measurement_record_id');
        $f = $this->batchFields($w, 'measurement');
        $f['target_id'] = $lot->subject_id;
        $f['previous_measurement_id'] = $original;
        $f['net_kg'] = '9.700';
        $id = app(DismantleBatches::class)->record($w['receiver'], $w['batch'], 'measurement', $f);
        $this->handoverBoundary();
        $this->assertSame($id, app(DismantleBatches::class)->record($w['receiver'], $w['batch'], 'measurement', $f));
        $this->assertSame($before, DB::table('batch_outputs')->count());
        $this->assertSame('9.500', DB::table('batch_records')->where('id', $original)->value('net_kg'));
        $this->assertSame('9.700', DB::table('batch_records')->where('id', $id)->value('net_kg'));
        $this->assertSame('pending_accounting', app(Context::class)->balance($w['batch'])['status']);
        $this->batchRecord($w, 'accounted');
        $this->assertSame('reconciled_on_site', app(Context::class)->balance($w['batch'])['status']);
        $this->batchRecord($w, 'output', ['output_type' => 'residue', 'net_kg' => '1.000']);
        $this->batchRecord($w, 'accounted');
        $b = app(Context::class)->balance($w['batch']);
        $this->assertSame('excess_variance', $b['status']);
        $this->assertEquals(-1.2, $b['difference_kg']);
        $this->assertFalse($b['recycling_complete']);
    }

    #[DataProvider('toleranceCases')]
    public function test_exact_tolerance_and_honest_signed_imbalance(string $input, string $output, string $status): void
    {
        $w = $this->batchWork(0);
        $this->batchRecord($w);
        $this->batchRecord($w, 'commit', ['net_kg' => $input]);
        $this->batchRecord($w, 'output', ['net_kg' => $output]);
        $this->batchRecord($w, 'accounted');
        $this->assertSame($status, app(Context::class)->balance($w['batch'])['status']);
        $this->assertDatabaseCount('batch_outputs', 1);
    }

    public static function toleranceCases(): array
    {
        return [['10.000', '9.800', 'reconciled_on_site'], ['10.000', '9.799', 'excess_variance'], ['10.000', '10.200', 'reconciled_on_site'], ['10.000', '10.201', 'excess_variance'], ['1.000', '0.900', 'reconciled_on_site'], ['1.000', '0.899', 'excess_variance'], ['0.001', '0.001', 'reconciled_on_site'], ['10.001', '9.801', 'reconciled_on_site'], ['10.001', '9.800', 'excess_variance']];
    }

    public function test_gross_tare_and_decimal_validation_preserve_exact_kg(): void
    {
        $m = Mass::measurement(['measure_mode' => 'gross_tare', 'gross_kg' => '12.345', 'tare_kg' => '2.344', 'net_kg' => '10.001', 'measurement_source' => 'Synthetic scale']);
        $this->assertSame(['measure_mode' => 'gross_tare', 'net_kg' => '10.001', 'gross_kg' => '12.345', 'tare_kg' => '2.344'], $m);
        foreach (['1.0001', '1e3', '-1', '0.1kg', '1000000000', '01.0', [], null] as $bad) {
            try {
                Mass::milli($bad, 'net_kg');
                $this->fail('No implicit conversion/rounding');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach ([['gross_kg' => '1', 'tare_kg' => '2'], ['gross_kg' => '2', 'tare_kg' => '1', 'net_kg' => '0.9']] as $bad) {
            try {
                Mass::measurement(['measure_mode' => 'gross_tare', 'measurement_source' => 'Synthetic'] + $bad);
                $this->fail('Inconsistent gross/tare must fail');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_detached_authorization_revalidates_real_prior_evidence_without_repeat_work(): void
    {
        $w = $this->batchWork();
        $s = $w['media'][0];
        $this->authorizeMedium($w, $s);
        $priorAuthorization = DB::table('media_authorization_current')->where('subject_id', $s)->value('record_id');
        $this->mediaSatisfied($this->mediumWork($w, $s));
        $this->batchRecord($w);
        $this->batchRecord($w, 'commit');
        $this->assertSame('review_required', app(\App\Support\Sanitization\Context::class)->state($s));
        $this->assertFalse(DB::selectOne('SELECT media_authorization_applicable(a) AS eligible FROM media_authorizations a WHERE id=?', [$priorAuthorization])->eligible);
        $this->authorizeDetached($w, $s);
        $this->mediaRecord($this->mediumWork($w, $s), 'revalidation');
        $this->assertSame('satisfied', app(\App\Support\Sanitization\Context::class)->state($s));
        $this->assertSame(1, DB::table('sanitization_records')->where('subject_id', $s)->where('kind', 'attempt')->count());
        $this->assertSame(3, DB::table('sanitization_records')->where('subject_id', $s)->orderByDesc('version')->value('authorization_context_version'));
        $this->mediaRecord($this->mediumWork($w, $s), 'attempt', $this->mediaFields($this->mediumWork($w, $s), 'attempt', 'failed'));
        $this->assertSame('failed', app(\App\Support\Sanitization\Context::class)->state($s));
    }

    public function test_unexpected_media_retains_blocking_history_without_new_identity_or_owner(): void
    {
        $w = $this->batchWork();
        $this->batchRecord($w);
        $this->batchRecord($w, 'commit');
        $before = DB::table('subjects')->count();
        $this->batchRecord($w, 'discrepancy');
        $this->assertSame($before, DB::table('subjects')->count());
        $this->assertSame('blocked_discrepancy', app(Context::class)->balance($w['batch'])['status']);
        foreach (['output', 'measurement', 'accounted'] as $kind) {
            try {
                $this->batchRecord($w, $kind);
                $this->fail('Unexpected media must block');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        try {
            $this->authorizeDetached($w, $w['media'][0]);
            $this->fail('No treatment authorization while discrepancy remains');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_stale_correction_and_changed_retry_payload_conflict_without_partial_effects(): void
    {
        $w = $this->batchWork();
        $this->batchRecord($w);
        $this->batchRecord($w, 'commit');
        $f = $this->batchFields($w, 'measurement');
        app(DismantleBatches::class)->record($w['receiver'], $w['batch'], 'measurement', $f);
        $this->handoverBoundary();
        foreach ([array_replace($f, ['net_kg' => '0.600']), array_replace($f, ['idempotency_key' => Str::uuid()->toString()])] as $bad) {
            $before = $this->counts();
            try {
                app(DismantleBatches::class)->record($w['receiver'], $w['batch'], 'measurement', $bad);
                $this->fail('Changed retry/stale form must conflict');
            } catch (HttpException $e) {
                $this->assertSame(409, $e->getStatusCode());
                $this->assertSame($before, $this->counts());
            }
        }
    }

    public function test_field_allowlist_rejects_protected_null_and_array_payloads(): void
    {
        $w = $this->batchWork();
        $this->batchRecord($w);
        $f = $this->batchFields($w, 'commit');
        foreach (['owner_user_id', 'facility_party_id', 'state', 'parent_id', 'subject_id', 'source_subject_version', 'production_record_id', 'recycling_complete'] as $key) {
            foreach ([null, []] as $value) {
                $before = $this->counts();
                try {
                    app(DismantleBatches::class)->record($w['receiver'], $w['batch'], 'commit', $f + [$key => $value]);
                    $this->fail('Protected fields cannot be supplied');
                } catch (ValidationException) {
                    $this->assertSame($before, $this->counts());
                }
            }
        }
    }

    public function test_browser_http_pages_feedback_labels_and_consumer_lineage_are_scoped(): void
    {
        $w = $this->batchWork();
        $this->batchRecord($w);
        $this->batchRecord($w, 'commit');
        $this->batchRecord($w, 'output');
        $this->receiptSignIn($w['receiver'])->get(route('batches.index'))->assertOk()->assertSee('Prepare batch draft');
        $this->get(route('batches.show', ['batch' => $w['batch'], 'action' => 'measurement']))->assertOk()->assertSee('Original current net kg')->assertSee('Record measurement correction');
        $this->get(route('batches.show', ['batch' => $w['batch'], 'action' => 'authorize', 'medium' => $w['media'][0]]))->assertOk()->assertSee('Authorize detached-drive treatment');
        $this->get(route('batches.label', $w['batch']))->assertOk()->assertSee('Print label');
        $this->get(route('batches.label', ['subject' => $w['batch'], 'qr' => 1]))->assertOk()->assertHeader('Content-Type', 'image/png');
        $code = DB::table('subjects')->where('id', $w['batch'])->value('public_code');
        $this->get(route('batches.lookup', ['code' => $code]))->assertRedirect(route('batches.show', $w['batch']));
        $this->receiptSignIn($w['owner'])->get(route('batches.show', $w['batch']))->assertOk()->assertSee('Your source items')->assertDontSee('10.000')->assertDontSee('Private synthetic')->assertDontSee('measurement_source')->assertDontSee($w['media'][0]);
        $this->get(route('batches.label', $w['batch']))->assertForbidden();
        $other = User::factory()->create();
        $this->receiptSignIn($other)->get(route('batches.show', $w['batch']))->assertNotFound();
    }

    public function test_validation_feedback_retains_only_command_and_associates_inputs(): void
    {
        $w = $this->batchWork();
        $this->batchRecord($w);
        $f = $this->batchFields($w, 'commit');
        $f['net_kg'] = '-1';
        $f['reason'] = 'Private reason must not be flashed';
        $this->receiptSignIn($w['receiver'])->post(route('batches.store', [$w['batch'], 'commit']), $f)->assertRedirect()->assertSessionHasErrors('net_kg');
        $this->assertSame(['idempotency_key' => $f['idempotency_key']], session()->getOldInput());
        $this->withCookie(config('session.cookie'), session()->getId())->get(route('batches.show', ['batch' => $w['batch'], 'action' => 'commit']))->assertOk()->assertSee('aria-invalid="true"', false)->assertSee('aria-describedby="net_kg-error"', false)->assertSee('data-error-summary', false);
    }

    public function test_direct_state_projection_and_detachment_writes_are_enforced_at_deferred_boundary(): void
    {
        $w = $this->batchWork();
        $this->batchRecord($w);
        $this->batchRecord($w, 'commit');
        $this->deferredFailure(fn () => DB::table('batch_outputs')->where('subject_id', $w['media'][0])->update(['measurement_record_id' => DB::table('dismantling_batches')->where('subject_id', $w['batch'])->value('input_measurement_id')]));
        $this->deferredFailure(fn () => DB::table('subjects')->where('id', $w['ids'][0])->update(['version' => DB::raw('version+1')]));
        $other = $this->batchWork(0);
        $this->deferredFailure(fn () => DB::table('items')->where('subject_id', $other['ids'][0])->update(['processing_state' => 'dismantled']));
        $this->expectException(QueryException::class);
        DB::table('batch_detachments')->where('subject_id', $w['media'][0])->delete();
    }

    public function test_recognized_standalone_drive_preserves_own_origin_receipts_and_assurance(): void
    {
        $w = $this->batchWork();
        $s = $this->standaloneAtParentFacility($w);
        $this->mediaSatisfied($this->mediumWork($w, $s));
        $beforeItem = (array) DB::table('items')->where('subject_id', $s)->first();
        $beforeReceipt = (array) DB::table('first_receipts')->where('subject_id', $s)->first();
        $beforeControl = (array) DB::table('custody_current')->where('subject_id', $s)->first();
        $this->inventoryRecord($w, 'link', ['existing_code' => DB::table('subjects')->where('id', $s)->value('public_code')]);
        $this->inventoryRecord($w, 'attest');
        $w['media'][] = $s;
        $this->batchRecord($w);
        $this->batchRecord($w, 'commit');
        $this->assertSame($beforeItem, (array) DB::table('items')->where('subject_id', $s)->first());
        $this->assertSame($beforeReceipt, (array) DB::table('first_receipts')->where('subject_id', $s)->first());
        $this->assertSame($beforeControl, (array) DB::table('custody_current')->where('subject_id', $s)->first());
        $this->assertSame(0, DB::table('media_origins')->where('subject_id', $s)->count());
        $this->assertSame('review_required', app(\App\Support\Sanitization\Context::class)->state($s));
        $this->authorizeDetached($w, $s);
        $this->mediaRecord($this->mediumWork($w, $s), 'revalidation');
        $this->assertSame('satisfied', app(\App\Support\Sanitization\Context::class)->state($s));
        $this->assertSame(1, DB::table('sanitization_records')->where('subject_id', $s)->where('kind', 'attempt')->count());
    }

    public function test_capability_revocation_blocks_accounting_and_authorization_and_other_facility_is_denied(): void
    {
        $w = $this->batchWork();
        $this->batchRecord($w);
        $this->batchRecord($w, 'commit');
        $other = $this->batchWork(0);
        $this->receiptSignIn($other['receiver'])->get(route('batches.show', $w['batch']))->assertNotFound();
        $parent = DB::table('items')->where('subject_id', $w['ids'][0])->first();
        $place = DB::table('parties')->where('id', app(Context::class)->party($w['receiver']->fresh()))->value('place_id');
        DB::table('place_categories')->where('place_id', $place)->where('category_id', $parent->category_id)->delete();
        $this->handoverBoundary();
        try {
            $this->batchRecord($w, 'output');
            $this->fail('Capability loss must stop new accounting');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('capabilities', $e->getMessage());
        }
        try {
            $this->authorizeDetached($w, $w['media'][0]);
            $this->fail('Capability loss must stop new authorization');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
        $this->assertDatabaseCount('batch_members', 1);
    }

    public function test_safety_hard_hold_and_destroyed_or_unknown_data_media_do_not_become_eligible(): void
    {
        foreach (['safety', 'destroyed', 'data_unknown'] as $case) {
            $w = $this->batchWork();
            $s = $w['ids'][0];
            if ($case === 'destroyed') {
                $this->authorizeMedium($w, $w['media'][0]);
                $mw = $this->mediumWork($w, $w['media'][0]);
                $f = $this->mediaFields($mw);
                $f['technique'] = 'media_shredding';
                $this->mediaRecord($mw, 'attempt', $f);
            } else {
                $f = $this->inspectionFields($s, 'laptop-healthy');
                $f['answers'][$case === 'safety' ? 'safety_concern' : 'data_status'] = $case === 'safety' ? 'YES' : 'UNKNOWN';
                app(InspectItems::class)->inspect($w['receiver'], $s, $f);
                $this->handoverBoundary();
                if ($case === 'data_unknown') {
                    app(InspectItems::class)->decide($w['receiver'], $s, $this->decisionFields($s, 'recycle'));
                    $this->inventoryRecord($w, 'attest');
                }
            }
            $this->batchRecord($w);
            try {
                $this->batchRecord($w, 'commit');
                $this->fail('Retained restriction must block');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_mixed_owner_aggregate_lineage_never_exposes_other_inputs_or_private_mass(): void
    {
        $w = $this->batchWork(0);
        $other = User::factory()->create();
        $s = $this->device($other)->subject_id;
        $request = $this->readyRequest($w['admin'], $other, [$s], 'drop_off');
        app(RecordVisit::class)->record($w['pointActor'], $request, $this->visitFields($request));
        $this->handoverBoundary();
        $transfer = array_replace($w, ['ids' => [$s], 'owner' => $other, 'sender' => $w['pointActor']]);
        $h = $this->initiateHandover($transfer);
        app(CustodyHandovers::class)->change($w['receiver'], $h, 'acknowledged', $this->acknowledgeFields($h));
        $this->handoverBoundary();
        $f = $this->inspectionFields($s, 'laptop-healthy');
        $f['answers']['data_status'] = 'NO';
        app(InspectItems::class)->inspect($w['receiver'], $s, $f);
        app(InspectItems::class)->decide($w['receiver'], $s, $this->decisionFields($s, 'recycle'));
        $this->handoverBoundary();
        $this->inventoryRecord($transfer, 'attest');
        $w['ids'][] = $s;
        $this->batchRecord($w);
        $this->batchRecord($w, 'commit');
        $this->batchRecord($w, 'output');
        $a = DB::table('subjects')->where('id', $w['ids'][0])->value('public_code');
        $b = DB::table('subjects')->where('id', $s)->value('public_code');
        $this->receiptSignIn($w['owner'])->get(route('batches.show', $w['batch']))->assertOk()->assertSee($a)->assertDontSee($b)->assertDontSee($other->id)->assertDontSee('9.500');
        $this->receiptSignIn($other)->get(route('batches.show', $w['batch']))->assertOk()->assertSee($b)->assertDontSee($a)->assertDontSee($w['owner']->id)->assertDontSee('9.500');
        $lot = DB::table('batch_outputs')->where('batch_id', $w['batch'])->first();
        $this->assertNull($lot->source_item_id);
        $this->assertSame(0, DB::table('items')->where('subject_id', $lot->subject_id)->count());
    }

    public function test_historical_parent_authorization_retry_returns_original_without_reactivation(): void
    {
        $w = $this->batchWork();
        $s = $w['media'][0];
        $f = $this->inventoryFields($w, 'authorize') + ['medium_id' => $s, 'expected_authorization_version' => 0];
        $id = app(InventoryMedia::class)->record($w['receiver'], $w['ids'][0], 'authorize', $f);
        $this->handoverBoundary();
        $oldAttempt = $this->mediaFields($this->mediumWork($w, $s));
        $this->batchRecord($w);
        $this->batchRecord($w, 'commit');
        $this->assertSame($id, app(InventoryMedia::class)->record($w['receiver'], $w['ids'][0], 'authorize', $f));
        $this->assertFalse(DB::selectOne('SELECT media_authorization_applicable(a) AS ready FROM media_authorizations a WHERE id=?', [$id])->ready);
        try {
            app(RecordSanitization::class)->record($w['receiver'], $s, 'attempt', $oldAttempt);
            $this->fail('Old parent context cannot execute a new assurance step');
        } catch (ValidationException) {
            $this->assertSame(0, DB::table('sanitization_records')->where('subject_id', $s)->count());
        }
    }

    public function test_complete_gross_values_are_required_at_database_boundary(): void
    {
        $w = $this->batchWork(0);
        $this->batchRecord($w);
        $this->batchRecord($w, 'commit');
        $b = DB::table('dismantling_batches')->where('subject_id', $w['batch'])->first();
        $r = (array) DB::table('batch_records')->where('id', $b->record_id)->first();
        $r['id'] = Str::uuid()->toString();
        $r['version'] = $b->version + 1;
        $r['predecessor_id'] = $b->record_id;
        $r['kind'] = 'measurement';
        $r['target_id'] = $w['batch'];
        $r['previous_measurement_id'] = $b->input_measurement_id;
        $r['checks'] = '{}';
        $r['context'] = json_encode(app(Context::class)->snapshot($w['receiver']->fresh(), $b), JSON_THROW_ON_ERROR);
        $r['measure_mode'] = 'gross_tare';
        $r['gross_kg'] = null;
        $r['tare_kg'] = '0.000';
        $r['command_id'] = Str::uuid()->toString();
        $r['event_id'] = Str::uuid()->toString();
        DB::beginTransaction();
        try {
            DB::table('batch_records')->insert($r);
            $this->fail('NULL gross cannot pass SQL three-valued logic');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Gross/tare measurement requires both actual values', $e->getMessage());
        } finally {
            DB::rollBack();
        }
        $this->assertSame($b->version, DB::table('dismantling_batches')->where('subject_id', $w['batch'])->value('version'));
    }

    public function test_pending_owner_return_has_withdrawal_recovery_but_configuration_hold_cannot_be_cleared(): void
    {
        foreach (['pending', 'configuration'] as $case) {
            $w = $this->batchWork(0);
            $s = $w['ids'][0];
            app(InspectItems::class)->decide($w['receiver'], $s, $this->decisionFields($s, 'refurbish'));
            $this->inventoryRecord($w, 'attest');
            if ($case === 'pending') {
                $this->reusePassed($w);
                $this->reuseRecord($w, 'authorize');
            } else {
                $this->reuseRecord($w, 'work', ['configuration' => 'UNKNOWN']);
            }
            app(InspectItems::class)->decide($w['receiver'], $s, $this->decisionFields($s, 'recycle'));
            $this->inventoryRecord($w, 'attest');
            $this->batchRecord($w);
            try {
                $this->batchRecord($w, 'commit');
                $this->fail('Retained work/return restriction must block');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
            if ($case === 'pending') {
                $this->reuseRecord($w, 'withdraw');
                $this->batchRecord($w, 'commit');
                $this->assertSame('dismantled', DB::table('items')->where('subject_id', $s)->value('processing_state'));
            } else {
                $this->assertSame('assessed', DB::table('items')->where('subject_id', $s)->value('processing_state'));
            }
        }
    }

    public function test_linked_drive_retained_work_restriction_cannot_be_hidden_by_parent_batch(): void
    {
        $w = $this->batchWork();
        $s = $this->standaloneAtParentFacility($w);
        $this->reuseRecord($this->mediumWork($w, $s), 'work', ['configuration' => 'UNKNOWN']);
        $this->inventoryRecord($w, 'link', ['existing_code' => DB::table('subjects')->where('id', $s)->value('public_code')]);
        $this->inventoryRecord($w, 'attest');
        $w['media'][] = $s;
        $this->batchRecord($w);
        $this->expectException(ValidationException::class);
        $this->batchRecord($w, 'commit');
    }

    public function test_supported_http_controls_complete_whole_drive_and_fan_accounting(): void
    {
        foreach ([false, true] as $fan) {
            $w = $this->batchWork($fan ? 0 : 1, $fan);
            $this->receiptSignIn($w['receiver']);
            $page = $this->get(route('batches.create'))->assertOk()->assertSee('Save draft selection');
            $this->assertSame(1, preg_match('~/batches/([a-f0-9-]{36})/draft~', $page->getContent(), $match));
            $w['batch'] = $match[1];
            $f = $this->batchFields($w);
            $page->assertSee($f['expected_context']);
            $this->post(route('batches.store', [$w['batch'], 'draft']), $f)->assertRedirect(route('batches.show', $w['batch']))->assertSessionHasNoErrors();
            $this->handoverBoundary();
            $this->get(route('batches.show', ['batch' => $w['batch'], 'action' => 'commit']))->assertOk()->assertSee('Every canonical surviving drive actually detached');
            $commitFields = $this->batchFields($w, 'commit');
            $this->post(route('batches.store', [$w['batch'], 'commit']), $commitFields)->assertRedirect()->assertSessionHasNoErrors();
            $this->handoverBoundary();
            $this->get(route('batches.show', $w['batch']))->assertOk();
            $this->post(route('batches.store', [$w['batch'], 'commit']), array_replace($commitFields, ['idempotency_key' => Str::uuid()->toString()]))->assertStatus(409)->assertSee('Reload Dismantling / Batches');
            $this->get(route('batches.show', ['batch' => $w['batch'], 'action' => 'output']))->assertOk()->assertSee('Aggregate output type')->assertSee('Unknown individual source');
            $this->post(route('batches.store', [$w['batch'], 'output']), array_replace($this->batchFields($w, 'output'), ['net_kg' => $fan ? '10.000' : '9.500']))->assertRedirect()->assertSessionHasNoErrors();
            $this->handoverBoundary();
            if (! $fan) {
                $this->get(route('batches.show', ['batch' => $w['batch'], 'action' => 'measurement', 'target' => $w['media'][0]]))->assertOk();
                $this->post(route('batches.store', [$w['batch'], 'measurement']), $this->batchFields($w, 'measurement'))->assertRedirect()->assertSessionHasNoErrors();
                $this->handoverBoundary();
            }
            $this->get(route('batches.show', ['batch' => $w['batch'], 'action' => 'measurement', 'target' => Str::uuid()->toString()]))->assertNotFound();
            $this->get(route('batches.show', ['batch' => $w['batch'], 'action' => 'measurement', 'target' => ['forged']]))->assertNotFound();
            $this->get(route('batches.show', ['batch' => $w['batch'], 'action' => 'accounted']))->assertOk();
            $this->post(route('batches.store', [$w['batch'], 'accounted']), $this->batchFields($w, 'accounted'))->assertRedirect()->assertSessionHasNoErrors();
            $this->handoverBoundary();
            $this->assertSame('reconciled_on_site', app(Context::class)->balance($w['batch'])['status']);
            if (! $fan) {
                $s = $w['media'][0];
                $a = app(AuthorizeBatchMedia::class);
                $f = $this->handoverEvidence() + ['expected_version' => 0, 'expected_context' => $a->token($w['receiver']->fresh(), $w['batch'], $s), 'reason' => 'Synthetic HTTP batch-controlled authorization', 'checks' => array_fill_keys(['identity_confirmed', 'safe_media', 'method_scope_known', 'no_unresolved_restriction'], 'YES')];
                $this->post(route('batches.authorize', [$w['batch'], $s]), $f)->assertRedirect(route('sanitization.show', $s))->assertSessionHasNoErrors();
                $this->handoverBoundary();
                foreach (['attempt', 'verification', 'validation'] as $kind) {
                    $mw = $this->mediumWork($w, $s);
                    $this->get(route('sanitization.show', ['subject' => $s, 'action' => $kind]))->assertOk();
                    $this->post(route('sanitization.store', [$s, $kind]), $this->mediaFields($mw, $kind))->assertRedirect()->assertSessionHasNoErrors();
                    $this->handoverBoundary();
                }
                $this->assertSame('satisfied', app(\App\Support\Sanitization\Context::class)->state($s));
            }
            $this->receiptSignIn($w['owner'])->get(route('batches.show', $w['batch']))->assertOk()->assertSee('Reconciled on site')->assertSee('Recycling completion remains unrecorded')->assertDontSee('Synthetic output scale');
        }
    }

    private function authorizeDetached(array $w, string $s): string
    {
        $a = app(AuthorizeBatchMedia::class);
        $f = $this->handoverEvidence() + ['expected_version' => DB::table('media_authorization_current')->where('subject_id', $s)->value('version') ?? 0, 'expected_context' => $a->token($w['receiver']->fresh(), $w['batch'], $s), 'reason' => 'Synthetic batch control, media identity, safety and policy scope', 'checks' => array_fill_keys(['identity_confirmed', 'safe_media', 'method_scope_known', 'no_unresolved_restriction'], 'YES')];
        $id = $a->record($w['receiver'], $w['batch'], $s, $f);
        $this->handoverBoundary();

        return $id;
    }

    private function deferredFailure(callable $write): void
    {
        DB::beginTransaction();
        $inserted = false;
        try {
            $write();
            $inserted = true;
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            $this->fail('Forced deferred evaluation must reject invalid reciprocal state');
        } catch (QueryException) {
            $this->assertTrue($inserted, 'Write must succeed before deferred enforcement is evaluated');
        } finally {
            DB::rollBack();
        }
        $this->handoverBoundary();
    }

    private function counts(): array
    {
        $c = [];
        foreach (['subjects', 'items', 'batch_records', 'batch_members', 'batch_detachments', 'batch_outputs', 'commands', 'domain_events', 'attachments', 'custody_current'] as $t) {
            $c[$t] = DB::table($t)->count();
        }

        return $c;
    }
}
