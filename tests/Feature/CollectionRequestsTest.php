<?php

namespace Tests\Feature;

use App\Actions\CollectionRequests;
use App\Actions\RegisterDevice;
use App\Enums\Role;
use App\Models\User;
use App\Support\Collection\Directory;
use App\Support\Collection\PickupPoint;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CollectionFixtures;
use Tests\TestCase;

class CollectionRequestsTest extends TestCase
{
    use CollectionFixtures, DatabaseTransactions;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1]);
        $this->owner = User::factory()->create();
        $this->signIn($this->owner);
        $this->directory();
    }

    private function signIn(User $user): void
    {
        $this->actingAs($user)->withSession(['auth_session_version' => $user->session_version]);
        $this->withCookie(config('session.cookie'), session()->getId());
    }

    private function wizardPost(int $step, array $fields)
    {
        $cipher = session('collection_draft');
        $version = $cipher ? Crypt::decrypt($cipher)['version'] : 0;

        return $this->post('/requests/create/'.$step, array_merge($fields, ['draft_version' => $version]));
    }

    private function submit(array $fields): string
    {
        return app(CollectionRequests::class)->submit($this->owner, $fields);
    }

    private function rejected(array $fields, string $field): void
    {
        try {
            $this->submit($fields);
            $this->fail('Expected safe rejection.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    private function changes(string $id, array $ids, string $operation = 'cancel'): array
    {
        $fields = ['items' => $ids, 'operation' => $operation, 'reason' => 'Synthetic change reason.', 'expected_version' => DB::table('requests')->where('id', $id)->value('version'), 'idempotency_key' => Str::uuid()->toString()];
        if ($operation === 'date') {
            $fields['preferred_date'] = now('Africa/Nairobi')->addDays(3)->toDateString();
        }

        return $fields;
    }

    public function test_pickup_and_drop_off_reserve_mixed_whole_and_parts_atomically_without_custody_changes(): void
    {
        foreach (['pickup', 'drop_off'] as $mode) {
            $ids = [];
            foreach ([['laptop', null], ['standalone_part', 'hdd'], ['standalone_part', 'ssd'], ['standalone_part', 'ict_cooling_fan']] as [$category,$subtype]) {
                $ids[] = $this->device($this->owner, $category, $subtype)->subject_id;
            }
            $before = DB::table('custody_current')->orderBy('subject_id')->get()->toJson();
            $id = $this->submit($this->requestFields($ids, $mode));
            $this->assertDatabaseHas('requests', ['id' => $id, 'state' => 'SUBMITTED', 'version' => 1]);
            $this->assertSame(4, DB::table('request_items')->where('request_id', $id)->where('active', true)->count());
            $this->assertSame($before, DB::table('custody_current')->orderBy('subject_id')->get()->toJson());
            $this->assertSame(0, DB::table('items')->whereIn('subject_id', $ids)->where('processing_state', '<>', 'registered')->count());
            $event = DB::table('domain_events')->where('aggregate_id', $id)->first();
            $this->assertNull($event->subject_id);
            $this->assertDatabaseHas('notification_outbox', ['event_id' => $event->id, 'state' => 'pending']);
            $this->get('/requests/'.$id)->assertOk()->assertSee('Awaiting review')->assertSee('not a booking')->assertHeader('Cache-Control', 'no-store, private');
        }
    }

    public function test_wizard_all_steps_private_draft_consent_feedback_and_safe_validation(): void
    {
        $device = $this->device($this->owner);
        $f = $this->requestFields([$device->subject_id]);
        $this->get('/requests/create/4')->assertConflict();
        $this->wizardPost(1, array_intersect_key($f, array_flip(['items', 'estimated_kg'])))->assertRedirect('/requests/create/2');
        $this->wizardPost(2, ['mode' => 'pickup'])->assertRedirect('/requests/create/3');
        $location = array_intersect_key($f, array_flip(['mode', 'service_area_id', 'address', 'latitude', 'longitude', 'contact', 'preferred_date']));
        $this->wizardPost(3, array_replace($location, ['latitude' => 'invalid']))->assertSessionHasErrors('latitude')->assertSessionMissing('_old_input.address')->assertSessionMissing('_old_input.contact');
        $this->assertTrue(session('errors')->has('latitude'));
        $cipher = session('collection_draft');
        $this->assertIsString($cipher);
        $this->assertStringNotContainsString($f['contact'], $cipher);
        $this->get('/requests/create/3')->assertViewHas('errors', fn ($bag) => $bag->has('latitude'))->assertOk()->assertSee('value="Synthetic landmark only"', false)->assertSee('aria-invalid="true"', false)->assertSee('latitude-error')->assertSee('data-error-summary', false);
        $this->wizardPost(3, array_merge($location, ['intent' => 'confirm_pickup']))->assertRedirect('/requests/create/3');
        $this->wizardPost(3, $location)->assertRedirect('/requests/create/4');
        $draft = Crypt::decrypt(session('collection_draft'));
        $key = $draft['fields']['idempotency_key'];
        $this->wizardPost(4, ['idempotency_key' => $key])->assertSessionHasErrors('privacy_ack');
        $response = $this->wizardPost(4, ['idempotency_key' => $key, 'privacy_ack' => '1'])->assertRedirect();
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('Your request was submitted.')->assertSee('role="status"', false);
        $this->wizardPost(4, ['idempotency_key' => $key, 'privacy_ack' => '1'])->assertRedirect($response->headers->get('Location'));
        $this->assertDatabaseCount('requests', 1);
    }

    public function test_final_submission_revalidates_point_reservations_and_current_hazards(): void
    {
        $item = $this->device($this->owner);
        $f = $this->requestFields([$item->subject_id], 'drop_off');
        $this->wizardPost(1, array_intersect_key($f, array_flip(['items', 'estimated_kg'])))->assertRedirect();
        $this->wizardPost(2, ['mode' => 'drop_off'])->assertRedirect();
        $this->wizardPost(3, array_intersect_key($f, array_flip(['mode', 'service_area_id', 'point_id', 'contact', 'preferred_date'])))->assertRedirect();
        DB::table('places')->where('id', $f['point_id'])->update(['active' => false]);
        $key = Crypt::decrypt(session('collection_draft'))['fields']['idempotency_key'];
        $this->wizardPost(4, ['idempotency_key' => $key, 'privacy_ack' => '1'])->assertSessionHasErrors('point_id');
        $this->assertDatabaseCount('requests', 0);
    }

    public function test_ownership_roles_suspension_session_versions_and_private_access(): void
    {
        $item = $this->device($this->owner);
        $f = $this->requestFields([$item->subject_id]);
        $id = $this->submit($f);
        $other = User::factory()->create();
        $this->signIn($other);
        $this->get('/requests/'.$id)->assertNotFound();
        $this->get('/requests')->assertOk()->assertDontSee($f['contact'])->assertDontSee(DB::table('requests')->where('id', $id)->value('public_code'));
        $this->post('/requests/'.$id.'/change', $this->changes($id, [$item->subject_id]))->assertNotFound();
        foreach ([Role::Collector, Role::Processor, Role::Administrator] as $role) {
            $other->membership()->update(['role' => $role]);
            $other->unsetRelation('membership');
            $this->signIn($other);
            $this->get('/requests/'.$id)->assertForbidden();
            $this->get('/requests/create')->assertForbidden();
        }
        $other->membership()->update(['role' => Role::Consumer]);
        $other->unsetRelation('membership');
        try {
            app(CollectionRequests::class)->submit($other, $f);
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items', $e->errors());
        }
        $this->signIn($this->owner);
        DB::table('users')->where('id', $this->owner->id)->update(['session_version' => 2]);
        $this->get('/requests/'.$id)->assertRedirect('/login');
    }

    public function test_point_compatibility_is_exact_for_category_subtype_and_status(): void
    {
        foreach (['hdd', 'ssd', 'ict_cooling_fan'] as $subtype) {
            $item = $this->device($this->owner, 'standalone_part', $subtype);
            $f = $this->requestFields([$item->subject_id], 'drop_off');
            foreach (['synthetic-study-whole-devices', 'synthetic-study-inactive'] as $code) {
                $bad = $f;
                $bad['point_id'] = DB::table('places')->where('code', $code)->value('id');
                $this->rejected($bad, 'point_id');
            }
            $bad = $f;
            $bad['point_id'] = Str::uuid()->toString();
            $this->rejected($bad, 'point_id');
            DB::table('place_categories')->where('place_id', $f['point_id'])->where('subtype', $subtype)->delete();
            $this->rejected($f, 'point_id');
        }
        $this->assertDatabaseCount('requests', 0);
        $this->get('/collection-points')->assertOk()->assertSee('Synthetic')->assertDontSee('Synthetic demonstration: inactive');
        DB::table('places')->update(['active' => false]);
        $this->get('/collection-points')->assertSee('No active compatible directory entries');
    }

    public function test_geography_boundary_tolerance_outside_invalid_and_inactive_area(): void
    {
        $item = $this->device($this->owner);
        $f = $this->requestFields([$item->subject_id]);
        foreach ([['-1.35', '36.75'], ['-1.20', '36.90'], ['-1.28', '36.750000']] as [$lat,$lon]) {
            $area = app(Directory::class)->area($f['service_area_id'], (float) $lat, (float) $lon);
            $this->assertSame($f['service_area_id'], $area->id);
        }
        foreach ([['-1.4', '36.82'], ['-1.28', '36.91'], ['91', '36.82'], ['bad', '36.82']] as [$lat,$lon]) {
            $bad = array_replace($f, ['latitude' => $lat, 'longitude' => $lon]);
            $this->rejected($bad, 'latitude');
        }
        $geometry = json_decode(DB::table('service_areas')->where('id', $f['service_area_id'])->value('geometry'), true);
        $this->assertTrue(app(Directory::class)->contains($geometry, -1.28, 36.75 - 0.0000005));
        $this->assertFalse(app(Directory::class)->contains($geometry, -1.28, 36.75 - 0.000002));
        DB::table('service_areas')->where('id', $f['service_area_id'])->update(['active' => false]);
        $this->rejected($f, 'latitude');
        $this->assertDatabaseCount('requests', 0);
    }

    public function test_safety_unknown_yes_are_review_and_unknown_function_is_not_a_hazard(): void
    {
        foreach (['NO' => [], 'YES' => ['safety_declared'], 'UNKNOWN' => ['safety_unknown']] as $answer => $expected) {
            $item = $this->device($this->owner, 'standalone_part', 'ssd', ['safety_concern' => $answer]);
            $id = $this->submit($this->requestFields([$item->subject_id]));
            $row = DB::table('request_items')->where('request_id', $id)->first();
            $this->assertSame($expected, json_decode($row->review_reasons, true));
            $this->assertSame('YES', $item->data_status);
            $this->assertDatabaseHas('requests', ['id' => $id, 'state' => 'SUBMITTED']);
        }
    }

    public function test_fan_revisions_revalidate_snapshot_history_version_and_retry_without_silent_clearance(): void
    {
        $item = $this->device($this->owner, 'standalone_part', 'ict_cooling_fan');
        $id = $this->submit($this->requestFields([$item->subject_id]));
        $before = DB::table('request_items')->where('request_id', $id)->first();
        $change = $this->changes($id, [$item->subject_id], 'date');
        $fields = ['idempotency_key' => Str::uuid()->toString(), 'expected_version' => 1, 'physical_damage' => 'UNKNOWN', 'safety_concern' => 'UNKNOWN', 'reason' => 'New uncertainty.', 'answers' => ['fan_scope' => 'UNKNOWN', 'fan_electronics' => 'UNKNOWN', 'previous_rotation' => 'UNKNOWN', 'past_unusual_noise' => 'UNKNOWN']];
        app(RegisterDevice::class)->revise($this->owner, $item, $fields);
        app(RegisterDevice::class)->revise($this->owner, $item, $fields);
        $row = DB::table('request_items')->where('request_id', $id)->first();
        $this->assertSame($before->initial_observation_id, $row->initial_observation_id);
        $this->assertSame(2, (int) $row->current_subject_version);
        $this->assertSame(['safety_unknown', 'fan_scope_unknown', 'fan_electronics_unknown'], json_decode($row->review_reasons, true));
        $this->assertDatabaseHas('requests', ['id' => $id, 'version' => 2]);
        $this->post('/requests/'.$id.'/change', $change)->assertConflict();
        $this->get('/requests/'.$id)->assertSee('Fan scope is uncertain')->assertSee('human review required');
        $fields['expected_version'] = 2;
        $fields['idempotency_key'] = Str::uuid()->toString();
        $fields['answers']['fan_scope'] = 'NO';
        $fields['answers']['fan_electronics'] = 'ADDITIONAL';
        app(RegisterDevice::class)->revise($this->owner, $item->refresh(), $fields);
        $this->get('/requests/'.$id)->assertSee('ordinary scheduling blocked');
        $this->post('/requests/'.$id.'/change', $this->changes($id, [$item->subject_id], 'date'))->assertSessionHasErrors('items');
        $this->post('/requests/'.$id.'/change', $this->changes($id, [$item->subject_id]))->assertRedirect();
        $this->rejected($this->requestFields([$item->subject_id]), 'items');
    }

    public function test_retries_conflicting_payload_and_new_reservation_are_distinct(): void
    {
        $item = $this->device($this->owner);
        $f = $this->requestFields([$item->subject_id]);
        $id = $this->submit($f);
        $this->assertSame($id, $this->submit($f));
        $changed = $f;
        $changed['contact'] = 'Other synthetic contact';
        try {
            $this->submit($changed);
            $this->fail();
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $new = $f;
        $new['idempotency_key'] = Str::uuid()->toString();
        $this->rejected($new, 'items');
        $this->assertDatabaseCount('requests', 1);
        $this->assertDatabaseCount('request_items', 1);
        $this->travel(5)->days();
        $this->assertSame($id, $this->submit($f));
    }

    public function test_cancel_subset_release_and_date_changes_keep_other_reservations_history_and_retry(): void
    {
        $a = $this->device($this->owner);
        $b = $this->device($this->owner);
        $id = $this->submit($this->requestFields([$a->subject_id, $b->subject_id]));
        $date = $this->changes($id, [$a->subject_id], 'date');
        $this->post('/requests/'.$id.'/change', $date)->assertRedirect();
        $this->post('/requests/'.$id.'/change', $date)->assertRedirect();
        $this->assertDatabaseHas('request_items', ['request_id' => $id, 'item_id' => $a->subject_id, 'preferred_date' => $date['preferred_date']]);
        $this->assertDatabaseHas('request_items', ['request_id' => $id, 'item_id' => $b->subject_id, 'active' => true]);
        $cancel = $this->changes($id, [$a->subject_id]);
        $this->post('/requests/'.$id.'/change', $cancel)->assertRedirect();
        $this->post('/requests/'.$id.'/change', $cancel)->assertRedirect();
        $this->assertDatabaseHas('request_items', ['request_id' => $id, 'item_id' => $a->subject_id, 'active' => false, 'outcome' => 'cancelled']);
        $this->assertDatabaseHas('request_items', ['request_id' => $id, 'item_id' => $b->subject_id, 'active' => true, 'outcome' => 'outstanding']);
        $this->assertDatabaseHas('requests', ['id' => $id, 'state' => 'SUBMITTED', 'version' => 3]);
        $this->assertSame(3, DB::table('domain_events')->where('aggregate_id', $id)->count());
        $new = $this->submit($this->requestFields([$a->subject_id]));
        $this->assertNotSame($id, $new);
        $this->post('/requests/'.$id.'/change', $this->changes($id, [$b->subject_id]))->assertRedirect();
        $this->assertDatabaseHas('requests', ['id' => $id, 'state' => 'CANCELLED']);
    }

    public function test_received_collected_and_cancelled_fixtures_cannot_be_changed_or_reopened(): void
    {
        $a = $this->device($this->owner);
        $b = $this->device($this->owner);
        $id = $this->submit($this->requestFields([$a->subject_id, $b->subject_id]));
        DB::table('request_items')->where('request_id', $id)->where('item_id', $a->subject_id)->update(['outcome' => 'collected', 'active' => false]);
        DB::table('items')->where('subject_id', $a->subject_id)->update(['processing_state' => 'received']);
        foreach (['cancel', 'date'] as $op) {
            $this->post('/requests/'.$id.'/change', $this->changes($id, [$a->subject_id], $op))->assertSessionHasErrors('items');
        }
        $this->post('/requests/'.$id.'/change', $this->changes($id, [$b->subject_id]))->assertRedirect();
        $this->assertDatabaseHas('requests', ['id' => $id, 'state' => 'COMPLETED']);
        try {
            DB::transaction(fn () => DB::table('request_items')->where('request_id', $id)->where('item_id', $a->subject_id)->update(['active' => true, 'outcome' => 'outstanding']));
            $this->fail();
        } catch (QueryException $e) {
            $this->assertSame('P0001', $e->errorInfo[0]);
        }
        $this->rejected($this->requestFields([$a->subject_id]), 'items');
    }

    public function test_direct_duplicate_constraint_and_failure_after_reservation_roll_back_all_records(): void
    {
        $a = $this->device($this->owner);
        $id = $this->submit($this->requestFields([$a->subject_id]));
        $row = (array) DB::table('request_items')->where('request_id', $id)->first();
        $row['id'] = Str::uuid()->toString();
        $b = $this->device($this->owner);
        $second = $this->submit($this->requestFields([$b->subject_id]));
        $row['request_id'] = $second;
        try {
            DB::transaction(fn () => DB::table('request_items')->insert($row));
            $this->fail();
        } catch (QueryException $e) {
            $this->assertSame('23505', $e->errorInfo[0]);
            $this->assertStringContainsString('request_items_active_item_unique', $e->errorInfo[2]);
        }
        $c = $this->device($this->owner);
        $counts = [];
        foreach (['requests', 'request_items', 'request_private_details', 'commands', 'domain_events', 'notification_outbox'] as $t) {
            $counts[$t] = DB::table($t)->count();
        }
        DB::unprepared("CREATE FUNCTION fail_request_outbox_fixture() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF EXISTS (SELECT 1 FROM domain_events WHERE id=NEW.event_id AND aggregate_kind='request') THEN RAISE EXCEPTION 'Synthetic outbox failure'; END IF; RETURN NEW; END; \$\$; CREATE TRIGGER fail_request_outbox_fixture BEFORE INSERT ON notification_outbox FOR EACH ROW EXECUTE FUNCTION fail_request_outbox_fixture();");
        try {
            $this->submit($this->requestFields([$c->subject_id]));
            $this->fail();
        } catch (QueryException $e) {
            $this->assertSame('P0001', $e->errorInfo[0]);
        }
        foreach ($counts as $t => $count) {
            $this->assertDatabaseCount($t, $count);
        }
        $this->assertFalse(DB::table('request_items')->where('item_id', $c->subject_id)->exists());
    }

    public function test_private_values_are_encrypted_safe_events_and_forged_fields_are_not_retained(): void
    {
        $item = $this->device($this->owner);
        $f = $this->requestFields([$item->subject_id]);
        $f['contact'] = '<script>private synthetic contact</script>';
        $id = $this->submit($f);
        $private = DB::table('request_private_details')->first();
        $this->assertNotSame($f['contact'], $private->encrypted_contact);
        $this->assertSame($f['contact'], Crypt::decryptString($private->encrypted_contact));
        $this->assertSame($f['address'], Crypt::decryptString($private->encrypted_address));
        $events = DB::table('domain_events')->where('aggregate_id', $id)->get()->toJson();
        foreach (['contact', 'address', 'latitude', 'longitude'] as $field) {
            $this->assertStringNotContainsString($f[$field], $events);
        }
        $this->get('/requests/'.$id)->assertOk()->assertDontSee('<script>private synthetic', false)->assertSee('&lt;script&gt;private synthetic', false);
        foreach (['consumer_id', 'state', 'private_details_id', 'assigned_collector', 'parent_id'] as $field) {
            foreach ([null, [], ['forged']] as $value) {
                $bad = $f;
                $bad['idempotency_key'] = Str::uuid()->toString();
                $bad[$field] = $value;
                $this->rejected($bad, 'submission');
            }
        }
        $this->wizardPost(2, ['mode' => 'pickup', 'state' => null])->assertConflict();
        $this->assertDatabaseCount('requests', 1);
    }

    public function test_directory_command_local_guard_repeat_preservation_and_no_automatic_restore(): void
    {
        $before = DB::table('places')->orderBy('id')->get()->toJson();
        $this->directory();
        $this->assertSame($before, DB::table('places')->orderBy('id')->get()->toJson());
        $point = DB::table('places')->where('code', 'synthetic-study-all-ict')->value('id');
        DB::table('places')->where('id', $point)->update(['active' => false]);
        DB::table('place_categories')->where('place_id', $point)->where('subtype', 'ssd')->delete();
        $this->directory();
        $this->assertDatabaseHas('places', ['id' => $point, 'active' => false]);
        $this->assertFalse(DB::table('place_categories')->where('place_id', $point)->where('subtype', 'ssd')->exists());
        $this->assertSame(1, Artisan::call('app:setup-collection-demo'));
        $this->assertStringContainsString('local environment', Artisan::output());
        $this->get('/collection-points')->assertOk();
    }

    public function test_invalid_mass_date_protected_change_fields_and_private_error_input(): void
    {
        $item = $this->device($this->owner);
        $f = $this->requestFields([$item->subject_id]);
        foreach (['0', '-1', '0.0001', 'bad', ['0.5']] as $mass) {
            $bad = $f;
            $bad['estimated_kg'][$item->subject_id] = $mass;
            $this->rejected($bad, 'estimated_kg.'.$item->subject_id);
        }
        $bad = $f;
        $bad['preferred_date'] = now('Africa/Nairobi')->subDay()->toDateString();
        $this->rejected($bad, 'preferred_date');
        $this->wizardPost(1, ['items' => [$item->subject_id], 'estimated_kg' => [$item->subject_id => 'bad']])->assertSessionHasErrors('estimated_kg.'.$item->subject_id);
        $this->get('/requests/create')->assertSee('value="bad"', false)->assertSee('aria-invalid="true"', false);
        $this->get('/requests/create/4')->assertConflict();
        $this->wizardPost(1, ['items' => 'forged', 'estimated_kg' => 'forged'])->assertSessionHasErrors('items');
        $this->get('/requests/create')->assertOk()->assertSee('Please check the following');
        $id = $this->submit($f);
        foreach ([null, [], ['forged']] as $value) {
            $fields = $this->changes($id, [$item->subject_id]);
            $fields['consumer_id'] = $value;
            $this->post('/requests/'.$id.'/change', $fields)->assertSessionHasErrors('submission')->assertSessionMissingInput('consumer_id')->assertSessionMissingInput('reason');
        }
        $fields = $this->changes($id, [$item->subject_id]);
        $fields['idempotency_key'] = ['forged'];
        $fields['expected_version'] = null;
        $this->post('/requests/'.$id.'/change', $fields)->assertSessionHasErrors(['idempotency_key', 'expected_version'])->assertSessionMissingInput('idempotency_key');
        $this->get('/requests/'.$id)->assertOk()->assertSee('Please check the following');
        $this->assertDatabaseHas('requests', ['id' => $id, 'version' => 1]);
    }

    public function test_owned_pagination_and_direct_snapshot_capability_and_geometry_constraints(): void
    {
        for ($i = 0; $i < 13; $i++) {
            $item = $this->device($this->owner);
            $this->submit($this->requestFields([$item->subject_id]));
        }
        $this->get('/requests')->assertOk()->assertSee('page=2')->assertDontSee('Synthetic contact only');
        $this->get('/requests?page=2')->assertOk();
        $row = DB::table('request_items')->first();
        $area = DB::table('service_areas')->first();
        $point = DB::table('places')->where('code', 'synthetic-study-all-ict')->first();
        foreach ([
            fn () => DB::table('request_items')->where('id', $row->id)->update(['initial_subject_version' => 99]),
            fn () => DB::table('request_items')->where('id', $row->id)->update(['current_observation_id' => Str::uuid()->toString()]),
            fn () => DB::table('service_areas')->where('id', $area->id)->update(['geometry' => json_encode(['type' => 'Polygon', 'coordinates' => []])]),
            fn () => DB::table('place_categories')->insert(['place_id' => $point->id, 'category_id' => DB::table('categories')->where('code', 'standalone_part')->value('id'), 'subtype' => '']),
        ] as $write) {
            try {
                DB::transaction($write);
                $this->fail('Direct inconsistent changes must fail.');
            } catch (QueryException $e) {
                $this->assertSame('P0001', $e->errorInfo[0]);
            }
        }
        $this->assertDatabaseCount('requests', 13);
        $this->assertSame(1, (int) $row->initial_subject_version);
    }

    public function test_stale_wizard_tab_cannot_submit_unreviewed_changed_draft(): void
    {
        $item = $this->device($this->owner);
        $f = $this->requestFields([$item->subject_id]);
        $this->get('/requests/create')->assertOk();
        $cipher = session('collection_draft');
        $oldVersion = Crypt::decrypt($cipher)['version'];
        $this->wizardPost(1, array_intersect_key($f, array_flip(['items', 'estimated_kg'])))->assertRedirect();
        $this->post('/requests/create/2', ['mode' => 'pickup', 'draft_version' => $oldVersion])->assertConflict();
        $this->assertDatabaseCount('requests', 0);
    }

    private function pickupLocation(): array
    {
        $f = $this->requestFields([$this->device($this->owner)->subject_id]);
        $this->wizardPost(1, array_intersect_key($f, array_flip(['items', 'estimated_kg'])))->assertRedirect('/requests/create/2');
        $this->wizardPost(2, ['mode' => 'pickup'])->assertRedirect('/requests/create/3');

        return array_intersect_key($f, array_flip(['mode', 'service_area_id', 'address', 'latitude', 'longitude', 'contact', 'preferred_date']));
    }

    public function test_pickup_requires_server_bound_confirmation_without_creating_records(): void
    {
        $location = $this->pickupLocation();
        $this->wizardPost(3, $location)->assertSessionHasErrors('pickup_point')->assertRedirect('/requests/create/3');
        $this->get('/requests/create/3')->assertSee('pickup_point-error')->assertSee('aria-invalid="true"', false)->assertSee('data-error-summary', false)->assertSee('Advanced location entry');
        $this->get('/requests/create/4')->assertConflict();
        foreach ([null, [], '1'] as $forged) {
            $this->wizardPost(3, array_merge($location, ['pickup_confirmation' => $forged]))->assertSessionHasErrors('submission');
        }
        foreach (['intent', 'pickup_changed'] as $protected) {
            foreach ([null, []] as $forged) {
                $this->wizardPost(3, array_merge($location, [$protected => $forged]))->assertSessionHasErrors('submission');
            }
        }
        $this->assertDatabaseCount('requests', 0);
        $this->assertDatabaseCount('request_private_details', 0);
        $this->wizardPost(3, array_merge($location, ['intent' => 'confirm_pickup']))->assertRedirect('/requests/create/3');
        $this->get('/requests/create/3')->assertSee('Selected pickup point confirmed.')->assertSee('This does not confirm service or a booking.');
        $this->wizardPost(3, $location)->assertRedirect('/requests/create/4');
    }

    public function test_point_can_be_confirmed_before_unrelated_fields_but_cannot_skip_them(): void
    {
        $location = $this->pickupLocation();
        $point = array_diff_key($location, array_flip(['address', 'contact', 'preferred_date']));
        $this->wizardPost(3, array_merge($point, ['intent' => 'confirm_pickup']))->assertRedirect('/requests/create/3');
        $this->assertTrue(PickupPoint::confirmed(Crypt::decrypt(session('collection_draft'))));
        $this->wizardPost(3, $point)->assertSessionHasErrors(['address', 'contact', 'preferred_date']);
        $this->assertTrue(PickupPoint::confirmed(Crypt::decrypt(session('collection_draft'))));
        $this->wizardPost(3, $location)->assertRedirect('/requests/create/4');
    }

    public function test_location_change_and_change_then_return_require_fresh_confirmation(): void
    {
        $location = $this->pickupLocation();
        $this->wizardPost(3, array_merge($location, ['intent' => 'confirm_pickup']))->assertRedirect();
        $changed = array_replace($location, ['latitude' => '-1.290000']);
        $this->wizardPost(3, $changed)->assertSessionHasErrors('pickup_point');
        $this->assertArrayNotHasKey('pickup_confirmation', Crypt::decrypt(session('collection_draft')));
        $this->wizardPost(3, array_merge($changed, ['intent' => 'confirm_pickup']))->assertRedirect();
        $this->wizardPost(3, array_merge($changed, ['pickup_changed' => '1']))->assertSessionHasErrors('pickup_point');
        $this->wizardPost(3, array_merge($changed, ['pickup_changed' => '1', 'intent' => 'confirm_pickup']))->assertRedirect('/requests/create/3');
        $this->wizardPost(3, $changed)->assertRedirect('/requests/create/4');
        $area = (array) DB::table('service_areas')->where('id', $location['service_area_id'])->first();
        $area['id'] = Str::uuid()->toString();
        $area['version_code'] = 'SYNTHETIC-CONFIRMATION-ONLY';
        DB::table('service_areas')->insert($area);
        $this->wizardPost(3, array_replace($changed, ['service_area_id' => $area['id']]))->assertSessionHasErrors('pickup_point');
        $this->assertArrayNotHasKey('pickup_confirmation', Crypt::decrypt(session('collection_draft')));
        $this->assertDatabaseCount('requests', 0);
    }

    public function test_back_navigation_and_unrelated_errors_preserve_confirmed_private_selection(): void
    {
        $location = $this->pickupLocation();
        $this->wizardPost(3, array_merge($location, ['intent' => 'confirm_pickup']))->assertRedirect();
        $this->wizardPost(3, array_replace($location, ['preferred_date' => 'invalid']))->assertSessionHasErrors('preferred_date');
        $draft = Crypt::decrypt(session('collection_draft'));
        $this->assertTrue(PickupPoint::confirmed($draft));
        $this->assertStringNotContainsString($location['latitude'], session('collection_draft'));
        $this->assertStringNotContainsString($location['address'], session('collection_draft'));
        $this->assertNull(session('_old_input'));
        $this->wizardPost(1, array_intersect_key($draft['fields'], array_flip(['items', 'estimated_kg'])))->assertRedirect();
        $this->wizardPost(2, ['mode' => 'pickup'])->assertRedirect();
        $this->get('/requests/create/3')->assertSee('Selected pickup point confirmed.')->assertSee('value="Synthetic landmark only"', false);
        $this->wizardPost(3, $location)->assertRedirect('/requests/create/4');
        $this->wizardPost(2, ['mode' => 'drop_off'])->assertRedirect();
        $draft = Crypt::decrypt(session('collection_draft'));
        $this->assertArrayNotHasKey('pickup_confirmation', $draft);
        $this->assertArrayNotHasKey('latitude', $draft['fields']);
        $this->get('/requests/create/3')->assertDontSee('data-pickup-location', false)->assertSee('Compatible drop-off point');
    }

    public function test_confirmation_rejects_invalid_out_of_area_and_inactive_coordinates(): void
    {
        $location = $this->pickupLocation();
        foreach ([['latitude' => '91'], ['longitude' => '181'], ['latitude' => []], ['latitude' => '-1.1234567'], ['latitude' => '-2']] as $invalid) {
            $this->wizardPost(3, array_merge($location, $invalid, ['intent' => 'confirm_pickup']))->assertSessionHasErrors();
            $this->assertArrayNotHasKey('pickup_confirmation', Crypt::decrypt(session('collection_draft')));
        }
        $this->wizardPost(3, array_merge($location, ['intent' => 'confirm_pickup']))->assertRedirect();
        DB::table('service_areas')->where('id', $location['service_area_id'])->update(['active' => false]);
        $this->wizardPost(3, $location)->assertSessionHasErrors('latitude');
        $this->assertDatabaseCount('requests', 0);
    }

    public function test_old_unconfirmed_draft_returns_to_location_but_successful_historical_retry_still_replays(): void
    {
        $location = $this->pickupLocation();
        $draft = Crypt::decrypt(session('collection_draft'));
        $draft['fields'] = array_merge($draft['fields'], $location);
        $draft['completed'] = 3;
        $this->withSession(['collection_draft' => Crypt::encrypt($draft)]);
        session()->save();
        $this->get('/requests/create/4')->assertSee('Pickup point needs confirmation.')->assertDontSee('Selected point confirmed.');
        $final = ['privacy_ack' => '1', 'idempotency_key' => $draft['fields']['idempotency_key']];
        $this->wizardPost(4, $final)->assertRedirect('/requests/create/3')->assertSessionHasErrors('pickup_point');
        $this->assertDatabaseCount('requests', 0);
        // Simulate a successful command created before map confirmation existed.
        $id = $this->submit(array_merge($draft['fields'], $final));
        $this->withSession(['collection_draft' => Crypt::encrypt($draft)]);
        session()->save();
        $this->wizardPost(4, $final)->assertRedirect('/requests/'.$id);
        $this->assertDatabaseCount('requests', 1);
        $draft['fields']['longitude'] = '36.830000';
        $this->withSession(['collection_draft' => Crypt::encrypt($draft)]);
        session()->save();
        $this->wizardPost(4, $final)->assertConflict();
    }

    public function test_map_disabled_fallback_notice_and_server_area_checks_remain_available(): void
    {
        config(['maps.enabled' => false]);
        $location = $this->pickupLocation();
        $this->get('/requests/create/3')->assertSee('data-map-enabled="false"', false)->assertSee('Advanced location entry')->assertSee('Confirm pickup point')->assertSee('Synthetic study boundary')->assertSee('name="latitude"', false)->assertHeader('Cache-Control', 'no-store, private');
        $this->wizardPost(3, array_merge($location, ['intent' => 'confirm_pickup']))->assertRedirect();
        $this->wizardPost(3, $location)->assertRedirect('/requests/create/4');
        $this->get('/privacy-notice')->assertSee('2026-10-09.1')->assertSee('approximate area')->assertSee('Advanced location entry');
        $this->assertFileExists(resource_path('views/privacy/notices/2026-10-06-1.blade.php'));
    }
}
