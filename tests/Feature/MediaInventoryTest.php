<?php

namespace Tests\Feature;

use App\Actions\CustodyHandovers;
use App\Actions\InspectItems;
use App\Actions\InventoryMedia;
use App\Actions\RecordVisit;
use App\Models\User;
use App\Support\Collection\AdminMutation;
use App\Support\MediaInventory\Context;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\MediaInventoryFixtures;
use Tests\TestCase;

class MediaInventoryTest extends TestCase
{
    use DatabaseTransactions, MediaInventoryFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1]);
    }

    public function test_two_media_share_canonical_facets_and_require_separate_current_assurance(): void
    {
        $w = $this->inventoryWork();
        $parent = $w['ids'][0];
        $before = $this->mediaPreservation();
        $s = $this->identifyMedium($w);
        $h = $this->identifyMedium($w, 'hdd');
        $this->inventoryRecord($w, 'attest');
        foreach ([$s, $h] as $medium) {
            $this->authorizeMedium($w, $medium);
            $this->assertSame($medium, DB::table('storage_assets')->where('subject_id', $medium)->value('subject_id'));
            $this->assertSame('YES', DB::table('items')->where('subject_id', $medium)->value('data_status'));
            $this->assertDatabaseMissing('first_receipts', ['subject_id' => $medium]);
            $this->assertDatabaseMissing('assessments', ['subject_id' => $medium]);
            $this->mediaSatisfied($this->mediumWork($w, $medium));
            if ($medium === $s) {
                $this->assertSame('blocked', app(Context::class)->coverage($parent)['status']);
            }
        }
        $coverage = app(Context::class)->coverage($parent);
        $this->assertSame('satisfied_attested', $coverage['status']);
        $this->assertFalse($coverage['reuse_authorized']);
        $this->assertDatabaseCount('sanitization_records', 6);
        foreach (['users', 'memberships', 'first_receipts', 'handover_receipts', 'assessments', 'decisions', 'request_items', 'requests', 'route_claims', 'route_approvals', 'route_consumptions', 'notification_outbox'] as $t) {
            $this->assertSame($before[$t], DB::table($t)->get()->toJson(), $t);
        }
    }

    public function test_unknown_serials_remain_distinct_and_global_duplicate_rejection_is_atomic(): void
    {
        $w = $this->inventoryWork();
        $s = $this->identifyMedium($w);
        $t = $this->identifyMedium($w);
        $this->assertNotSame($s, $t);
        $known = $this->inventoryRecord($w, 'identify', ['identifier' => ' SYN- 009 ']);
        $counts = $this->counts();
        $this->reject(fn () => $this->inventoryRecord($w, 'identify', ['identifier' => 'syn009']), 'identifier');
        $this->assertSame($counts, $this->counts());
        $this->assertNotNull($known);
        $this->receiptSignIn($w['owner'])->get(route('items.create'))->assertOk()->assertDontSee('processor_media');
        $this->receiptSignIn($w['owner'])->post(route('items.store'), ['category' => 'processor_media', 'subtype' => 'ssd'])->assertSessionHasErrors();
    }

    public function test_unknown_entries_resolve_with_stable_identity_and_bound_corrections_preserve_claims(): void
    {
        $w = $this->inventoryWork();
        $r = $this->inventoryRecord($w, 'unresolved');
        $entry = DB::table('media_inventory_records')->where('id', $r)->value('entry_id');
        $this->assertDatabaseCount('media_origins', 0);
        $this->reject(fn () => $this->inventoryRecord($w, 'attest'), 'completeness');
        $this->inventoryRecord($w, 'identify', ['entry_id' => $entry]);
        $s = DB::table('media_inventory_entries')->where('id', $entry)->value('medium_id');
        $this->assertDatabaseCount('media_inventory_entries', 1);
        $this->inventoryRecord($w, 'attest');
        $this->authorizeMedium($w, $s);
        $this->mediaSatisfied($this->mediumWork($w, $s));
        $this->inventoryRecord($w, 'correct', ['entry_id' => $entry, 'entry_state' => 'disputed']);
        $this->assertSame($s, DB::table('media_inventory_entries')->where('id', $entry)->value('medium_id'));
        $this->assertSame('review_required', app(\App\Support\Sanitization\Context::class)->state($s));
        $this->reject(fn () => $this->inventoryRecord($w, 'correct', ['entry_id' => $entry, 'entry_state' => 'not_present']), 'entry_state');
        $this->reject(fn () => $this->inventoryRecord($w, 'attest'), 'completeness');
        $this->assertSame('blocked', app(Context::class)->coverage($w['ids'][0])['status']);
    }

    public function test_evidenced_na_needs_technician_no_complete_attestation_and_no_media(): void
    {
        $w = $this->inventoryWork('NO');
        $this->assertSame('blocked', app(Context::class)->coverage($w['ids'][0])['status']);
        $this->inventoryRecord($w, 'attest');
        $this->assertSame('not_applicable_attested', app(Context::class)->coverage($w['ids'][0])['status']);
        $this->identifyMedium($w);
        $this->inventoryRecord($w, 'attest');
        $this->assertSame('blocked', app(Context::class)->coverage($w['ids'][0])['status']);
    }

    public function test_unknown_configuration_and_unsupported_media_do_not_clear_coverage(): void
    {
        $w = $this->inventoryWork('UNKNOWN');
        $s = $this->identifyMedium($w, 'other_nonvolatile');
        $this->inventoryRecord($w, 'attest');
        $this->reject(fn () => $this->authorizeMedium($w, $s), 'medium_id');
        $coverage = app(Context::class)->coverage($w['ids'][0]);
        $this->assertContains('Treatment support unavailable', $coverage['reasons']);
        $this->assertContains('Current technician data-bearing status is unknown', $coverage['reasons']);
        $this->receiptSignIn($w['receiver'])->get(route('sanitization.show', $s))->assertOk()->assertSee('Treatment support unavailable');
    }

    public function test_unbound_correction_is_retained_but_unknown_checks_block_completeness(): void
    {
        $w = $this->inventoryWork('NO');
        $r = $this->inventoryRecord($w, 'unresolved');
        $entry = DB::table('media_inventory_records')->where('id', $r)->value('entry_id');
        $this->inventoryRecord($w, 'correct', ['entry_id' => $entry, 'entry_state' => 'not_present']);
        $f = $this->inventoryFields($w, 'attest');
        $f['checks']['configuration_storage'] = 'UNKNOWN';
        $this->reject(fn () => app(InventoryMedia::class)->record($w['receiver'], $w['ids'][0], 'attest', $f), 'checks');
        $this->inventoryRecord($w, 'attest');
        $this->assertDatabaseCount('media_inventory_entries', 1);
        $this->assertDatabaseCount('media_inventory_records', 3);
        $this->assertSame('not_applicable_attested', app(Context::class)->coverage($w['ids'][0])['status']);
    }

    public function test_reassessment_and_inventory_changes_need_new_authorization_and_real_applicability_review(): void
    {
        $w = $this->inventoryWork();
        $s = $this->identifyMedium($w);
        $this->inventoryRecord($w, 'attest');
        $this->authorizeMedium($w, $s);
        $mw = $this->mediumWork($w, $s);
        $this->mediaSatisfied($mw);
        $parent = $w['ids'][0];
        $f = $this->inspectionFields($parent, 'laptop-healthy');
        $f['answers']['data_status'] = 'YES';
        app(InspectItems::class)->inspect($w['receiver'], $parent, $f);
        app(InspectItems::class)->decide($w['receiver'], $parent, $this->decisionFields($parent));
        $this->handoverBoundary();
        $this->assertSame('review_required', app(\App\Support\Sanitization\Context::class)->state($s));
        $this->inventoryRecord($w, 'attest');
        $this->authorizeMedium($w, $s);
        $this->mediaRecord($mw, 'revalidation');
        $this->assertDatabaseCount('sanitization_records', 4);
        $this->assertSame('satisfied_attested', app(Context::class)->coverage($parent)['status']);
        $negative = $this->mediaFields($mw, 'attempt', 'failed');
        $this->mediaRecord($mw, 'attempt', $negative);
        $this->assertSame('blocked', app(Context::class)->coverage($parent)['status']);
    }

    public function test_reported_destruction_survives_disputed_identity_and_never_authorizes_reuse(): void
    {
        $w = $this->inventoryWork();
        $s = $this->identifyMedium($w);
        $this->inventoryRecord($w, 'attest');
        $this->authorizeMedium($w, $s);
        $mw = $this->mediumWork($w, $s);
        $f = $this->mediaFields($mw);
        $f['technique'] = 'media_shredding';
        $this->mediaSatisfied($mw, $f);
        $this->assertTrue(app(Context::class)->coverage($w['ids'][0])['destruction_concern']);
        $entry = DB::table('media_inventory_entries')->where('medium_id', $s)->value('id');
        $this->inventoryRecord($w, 'correct', ['entry_id' => $entry, 'entry_state' => 'disputed']);
        $c = app(Context::class)->coverage($w['ids'][0]);
        $this->assertTrue($c['destruction_concern']);
        $this->assertFalse($c['reuse_authorized']);
    }

    public function test_same_command_retries_precede_mutable_checks_and_changed_payloads_conflict(): void
    {
        $w = $this->inventoryWork();
        $f = $this->inventoryFields($w);
        $first = app(InventoryMedia::class)->record($w['receiver'], $w['ids'][0], 'identify', $f);
        $this->handoverBoundary();
        $this->inventoryRecord($w, 'attest');
        $this->assertSame($first, app(InventoryMedia::class)->record($w['receiver'], $w['ids'][0], 'identify', $f));
        $this->receiptSignIn($w['receiver'])->post(route('media-inventory.store', [$w['ids'][0], 'identify']), array_replace($f, ['reason' => 'changed']))->assertConflict()->assertSee('Reload Media inventory');
        $new = $f;
        $new['idempotency_key'] = Str::uuid()->toString();
        $this->receiptSignIn($w['receiver'])->post(route('media-inventory.store', [$w['ids'][0], 'identify']), $new)->assertConflict();
        $this->assertDatabaseCount('media_origins', 1);
    }

    public function test_forged_fields_private_flash_and_field_associations_are_safe(): void
    {
        $w = $this->inventoryWork();
        $f = $this->inventoryFields($w);
        foreach (['parent_id' => null, 'owner_user_id' => [], 'data_status' => 'NO', 'origin' => null] as $key => $value) {
            $this->reject(fn () => app(InventoryMedia::class)->record($w['receiver'], $w['ids'][0], 'identify', $f + [$key => $value]), 'submission');
        }
        $f['checks']['physical_match'] = 'UNKNOWN';
        $f['identifier'] = 'PRIVATE-SYN-SECRET';
        $this->receiptSignIn($w['receiver'])->post(route('media-inventory.store', [$w['ids'][0], 'identify']), $f)->assertRedirect()->assertSessionHasErrors('checks')->assertSessionMissing('_old_input.identifier')->assertSessionMissing('_old_input.reason');
        $this->withCookie(config('session.cookie'), session()->getId())->get(route('media-inventory.show', ['subject' => $w['ids'][0], 'action' => 'identify']))->assertOk()->assertSee('Record inventory observation')->assertSee('checks_physical_match')->assertSee('id="form-errors"', false)->assertDontSee('PRIVATE-SYN-SECRET');
        $bad = $this->inventoryFields($w);
        unset($bad['checks']['physical_match']);
        $this->post(route('media-inventory.store', [$w['ids'][0], 'identify']), $bad)->assertSessionHasErrors('checks.physical_match');
        $this->withCookie(config('session.cookie'), session()->getId())->get(route('media-inventory.show', ['subject' => $w['ids'][0], 'action' => 'identify']))->assertOk()->assertSee('aria-invalid="true"', false)->assertSee('checks_physical_match-error', false)->assertSee('href="#checks_physical_match"', false);

    }

    public function test_private_inventory_history_and_evidence_deny_other_roles_and_revoked_scope(): void
    {
        Storage::fake('directory_evidence');
        $w = $this->inventoryWork();
        $f = $this->inventoryFields($w);
        unset($f['reference_issuer'],$f['reference_code'],$f['reference_attested']);
        $f['evidence_kind'] = 'attachment';
        $id = app(InventoryMedia::class)->record($w['receiver'], $w['ids'][0], 'identify', $f, UploadedFile::fake()->image('private.jpg'));
        $this->handoverBoundary();
        $url = route('media-inventory.evidence', ['kind' => 'inventory', 'record' => $id]);
        $this->receiptSignIn($w['receiver'])->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->receiptSignIn($w['owner'])->get(route('media-inventory.show', $w['ids'][0]))->assertOk()->assertDontSee('Private synthetic')->assertDontSee('Download private');
        $this->receiptSignIn($w['owner'])->get($url)->assertForbidden();
        $this->receiptSignIn($w['admin'])->get(route('media-inventory.show', $w['ids'][0]))->assertForbidden();
        $other = User::factory()->create();
        $this->receiptSignIn($other)->get(route('media-inventory.show', $w['ids'][0]))->assertNotFound();
        DB::table('memberships')->where('user_id', $w['receiver']->id)->update(['active' => false, 'version' => DB::raw('version+1')]);
        $this->receiptSignIn($w['receiver'])->get($url)->assertStatus(302);
    }

    public function test_direct_forged_projection_and_missing_reciprocals_fail_at_constraint_boundary(): void
    {
        $w = $this->inventoryWork();
        $s = $this->identifyMedium($w);
        $this->invalidWrite(fn () => DB::table('media_inventory_entries')->where('medium_id', $s)->update(['medium_id' => null, 'state' => 'not_present']));
        $this->invalidWrite(fn () => DB::table('custody_current')->where('subject_id', $w['ids'][0])->update(['version' => DB::raw('version+1')]));
        $this->invalidWrite(fn () => DB::table('custody_current')->where('subject_id', $s)->update(['version' => 2]));
        $this->invalidWrite(fn () => DB::table('media_inventory_current')->where('parent_id', $w['ids'][0])->update(['version' => 100]));
        $this->invalidWrite(fn () => AdminMutation::command($w['receiver'], 'media_inventory_identify:'.$w['ids'][0], Str::uuid()->toString(), 'synthetic', Str::uuid()->toString()));
        $this->invalidWrite(function () use ($w) {
            $r = (array) DB::table('media_inventory_records')->where('parent_id', $w['ids'][0])->first();
            $r['id'] = Str::uuid()->toString();
            $r['version'] = 2;
            $r['kind'] = 'unresolved';
            $r['medium_id'] = null;
            $r['entry_state'] = 'unresolved';
            $r['checks'] = '{}';
            $r['predecessor_id'] = DB::table('media_inventory_current')->where('parent_id', $w['ids'][0])->value('record_id');
            $r['entry_id'] = Str::uuid()->toString();
            $r['command_id'] = AdminMutation::command($w['receiver'], 'media_inventory_unresolved:'.$w['ids'][0], Str::uuid()->toString(), 'synthetic', $r['id']);
            $r['event_id'] = Str::uuid()->toString();
            $event = (array) DB::table('domain_events')->where('aggregate_kind', 'media_inventory')->first();
            $event['id'] = $r['event_id'];
            $event['aggregate_version'] = 2;
            $event['type'] = 'media_inventory_unresolved';
            $event['command_id'] = $r['command_id'];
            DB::table('domain_events')->insert($event);
            DB::table('media_inventory_records')->insert($r);
            // Insert succeeds; the omitted entry/current projection must fail when constraints evaluate.
            $this->assertDatabaseHas('media_inventory_records', ['id' => $r['id']]);
        });
        $this->assertDatabaseCount('media_inventory_records', 1);
    }

    public function test_existing_standalone_recognition_preserves_identity_and_requires_applicability_review(): void
    {
        $w = $this->inventoryWork();
        $drive = $this->device($w['owner'], 'standalone_part', 'ssd')->subject_id;
        $request = $this->readyRequest($w['admin'], $w['owner'], [$drive], 'drop_off');
        app(RecordVisit::class)->record($w['pointActor'], $request, $this->visitFields($request));
        $this->handoverBoundary();
        $transfer = array_replace($w, ['ids' => [$drive], 'sender' => $w['pointActor']]);
        $id = $this->initiateHandover($transfer);
        app(CustodyHandovers::class)->change($w['receiver'], $id, 'acknowledged', $this->acknowledgeFields($id));
        $this->handoverBoundary();
        app(InspectItems::class)->inspect($w['receiver'], $drive, $this->inspectionFields($drive));
        app(InspectItems::class)->decide($w['receiver'], $drive, $this->decisionFields($drive));
        $this->handoverBoundary();
        $mw = $this->mediumWork($w, $drive);
        $this->mediaSatisfied($mw);
        $before = DB::table('items')->where('subject_id', $drive)->first();
        $this->inventoryRecord($w, 'link', ['existing_code' => DB::table('subjects')->where('id', $drive)->value('public_code')]);
        $this->inventoryRecord($w, 'attest');
        $this->assertEquals($before, DB::table('items')->where('subject_id', $drive)->first());
        $this->assertDatabaseMissing('media_origins', ['subject_id' => $drive]);
        $this->assertSame('review_required', app(\App\Support\Sanitization\Context::class)->state($drive));
        $this->reject(fn () => $this->authorizeMedium($w, $drive), 'medium_id');
        $this->mediaRecord($mw, 'revalidation');
        $this->assertSame('satisfied_attested', app(Context::class)->coverage($w['ids'][0])['status']);
        $this->reject(fn () => $this->inventoryRecord($w, 'link', ['existing_code' => DB::table('subjects')->where('id', $drive)->value('public_code')]), 'existing_code');
    }

    public function test_missing_na_justification_and_conflicting_owner_are_safely_rejected(): void
    {
        $w = $this->inventoryWork('NO');
        $f = $this->inventoryFields($w, 'attest');
        unset($f['na_justification']);
        $this->reject(fn () => app(InventoryMedia::class)->record($w['receiver'], $w['ids'][0], 'attest', $f), 'na_justification');
        $this->reject(fn () => $this->inventoryRecord($w, 'link', ['existing_code' => 'EWC-'.str_repeat('A', 32)]), 'existing_code');
        $this->assertDatabaseCount('media_inventory_records', 0);
        $s = $this->identifyMedium($w);
        $this->receiptSignIn($w['owner'])->get(route('items.show', $s))->assertOk()->assertSee('facility-identified');
        $this->receiptSignIn($w['owner'])->get(route('items.label', $s))->assertOk()->assertSee('Media identity');
        $this->receiptSignIn($w['owner'])->get(route('items.index'))->assertOk()->assertSee('Not a first receipt');
    }

    public function test_http_inventory_authorization_assurance_and_scoped_labels_are_reachable(): void
    {
        $w = $this->inventoryWork();
        $parent = $w['ids'][0];
        $this->receiptSignIn($w['receiver'])->get(route('media-inventory.show', ['subject' => $parent, 'action' => 'identify']))->assertOk()->assertSee('Record inventory observation')->assertSee('checks_physical_match');
        $this->post(route('media-inventory.store', [$parent, 'identify']), $this->inventoryFields($w))->assertRedirect(route('media-inventory.show', $parent))->assertSessionHasNoErrors();
        $s = DB::table('media_inventory_entries')->where('parent_id', $parent)->value('medium_id');
        $this->handoverBoundary();
        $this->get(route('assessments.index'))->assertOk()->assertSee(DB::table('subjects')->where('id', $parent)->value('public_code'))->assertDontSee(DB::table('subjects')->where('id', $s)->value('public_code'));
        $this->get(route('sanitization.index'))->assertOk()->assertSee('Parent Media inventory')->assertSee(DB::table('subjects')->where('id', $s)->value('public_code'));
        $this->post(route('media-inventory.store', [$parent, 'attest']), $this->inventoryFields($w, 'attest'))->assertRedirect()->assertSessionHasNoErrors();
        $this->handoverBoundary();
        $this->get(route('media-inventory.show', ['subject' => $parent, 'action' => 'authorize', 'medium' => $s]))->assertOk()->assertSee('name="expected_authorization_version" value="0"', false);
        $f = $this->inventoryFields($w, 'authorize') + ['medium_id' => $s, 'expected_authorization_version' => 0];
        $this->post(route('media-inventory.store', [$parent, 'authorize']), $f)->assertRedirect()->assertSessionHasNoErrors();
        $this->handoverBoundary();
        $mw = $this->mediumWork($w, $s);
        foreach (['attempt', 'verification', 'validation'] as $kind) {
            $this->get(route('sanitization.show', ['subject' => $s, 'action' => $kind]))->assertOk()->assertSee('Parent Media inventory');
            $this->post(route('sanitization.store', [$s, $kind]), $this->mediaFields($mw, $kind))->assertRedirect()->assertSessionHasNoErrors();
            $this->handoverBoundary();
        }
        $this->get(route('media-inventory.show', $parent))->assertOk()->assertSee('Data-treatment coverage satisfied (attested)');
        $this->get(route('media-inventory.label', $s))->assertOk()->assertSee('Media identity');
        $this->get(route('media-inventory.qr', $s))->assertOk()->assertHeader('Content-Type', 'image/png');
        $code = DB::table('subjects')->where('id', $s)->value('public_code');
        $this->get(route('items.lookup', $code))->assertRedirect(route('sanitization.show', $s));
        $this->get(route('items.lookup', DB::table('subjects')->where('id', $parent)->value('public_code')))->assertRedirect(route('media-inventory.show', $parent));
        $this->receiptSignIn($w['pointActor'])->get(route('media-inventory.qr', $s))->assertNotFound();
        $this->receiptSignIn($w['owner'])->get(route('media-inventory.label', $s))->assertForbidden();
    }

    public function test_existing_match_never_adopts_other_owner_or_other_facility_control(): void
    {
        $w = $this->inventoryWork();
        $otherOwner = User::factory()->create();
        $foreign = $this->standaloneAtParentFacility(array_replace($w, ['owner' => $otherOwner]));
        $otherFacility = $this->facility($w['point']);
        $otherReceiver = $this->pointStaff($w['admin'], $otherFacility);
        $elsewhere = $this->standaloneAtParentFacility(array_replace($w, ['receiver' => $otherReceiver, 'facility' => $otherFacility]));
        foreach ([$foreign, $elsewhere] as $id) {
            $before = DB::table('custody_current')->where('subject_id', $id)->first();
            $this->reject(fn () => $this->inventoryRecord($w, 'link', ['existing_code' => DB::table('subjects')->where('id', $id)->value('public_code')]), 'existing_code');
            $this->assertEquals($before, DB::table('custody_current')->where('subject_id', $id)->first());
            $this->assertDatabaseMissing('media_inventory_entries', ['medium_id' => $id]);
        }
        $this->assertDatabaseCount('media_inventory_records', 0);
    }

    public function test_explicit_media_capability_loss_blocks_parent_authorization_and_coverage(): void
    {
        $w = $this->inventoryWork();
        $s = $this->identifyMedium($w);
        $this->inventoryRecord($w, 'attest');
        $this->authorizeMedium($w, $s);
        $this->mediaSatisfied($this->mediumWork($w, $s));
        DB::table('place_categories')->where('place_id', $w['facility'])->where('subtype', 'ssd')->delete();
        $this->assertSame('review_required', app(\App\Support\Sanitization\Context::class)->state($s));
        $this->reject(fn () => $this->authorizeMedium($w, $s), 'medium_id');
        $this->assertSame('blocked', app(Context::class)->coverage($w['ids'][0])['status']);
    }

    public function test_authorization_retry_is_bound_to_its_original_parent_route(): void
    {
        $w = $this->inventoryWork();
        $s = $this->identifyMedium($w);
        $this->inventoryRecord($w, 'attest');
        $f = $this->inventoryFields($w, 'authorize') + ['medium_id' => $s, 'expected_authorization_version' => 0];
        $first = app(InventoryMedia::class)->record($w['receiver'], $w['ids'][0], 'authorize', $f);
        $this->handoverBoundary();
        $this->assertSame($first, app(InventoryMedia::class)->record($w['receiver'], $w['ids'][0], 'authorize', $f));
        $second = $this->device($w['owner'], 'laptop')->subject_id;
        $request = $this->readyRequest($w['admin'], $w['owner'], [$second], 'drop_off');
        app(RecordVisit::class)->record($w['pointActor'], $request, $this->visitFields($request));
        $this->handoverBoundary();
        $id = $this->initiateHandover(array_replace($w, ['ids' => [$second], 'sender' => $w['pointActor']]));
        app(CustodyHandovers::class)->change($w['receiver'], $id, 'acknowledged', $this->acknowledgeFields($id));
        $this->handoverBoundary();
        $this->receiptSignIn($w['receiver'])->post(route('media-inventory.store', [$second, 'authorize']), $f)->assertConflict();
        $this->assertDatabaseCount('media_authorizations', 1);
    }

    protected function reject(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail('Expected safe validation rejection');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    protected function invalidWrite(callable $action): void
    {
        try {
            DB::transaction(function () use ($action) {
                $action();
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
            $this->fail('Invalid direct write committed');
        } catch (QueryException $e) {
            $this->assertNotEmpty($e->errorInfo[0]);
        }
    }

    private function counts(): array
    {
        return array_map(fn ($t) => DB::table($t)->count(), ['subjects', 'items', 'storage_assets', 'domain_events', 'commands', 'media_inventory_records', 'media_origins', 'custody_current', 'attachments']);
    }
}
