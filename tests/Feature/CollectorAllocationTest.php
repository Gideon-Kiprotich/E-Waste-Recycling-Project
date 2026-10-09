<?php

namespace Tests\Feature;

use App\Actions\AllocateCollector;
use App\Actions\CollectionRequests;
use App\Actions\ManageCollector;
use App\Actions\ManageDirectory;
use App\Actions\RegisterDevice;
use App\Actions\ReviewRequest;
use App\Enums\Role;
use App\Models\User;
use App\Support\Assignments\Eligibility;
use App\Support\Assignments\Notices;
use App\Support\Collection\Review;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\AssignmentFixtures;
use Tests\TestCase;

class CollectorAllocationTest extends TestCase
{
    use AssignmentFixtures, DatabaseTransactions;

    private User $admin;

    private User $owner;

    private User $collector;

    private string $place;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1]);
        [$this->admin, $this->owner, $this->collector, $this->place] = $this->allocationSetup();
    }

    private function signIn(User $user): void
    {
        $this->actingAs($user)->withSession(['auth_session_version' => $user->session_version]);
        session()->save();
        $this->withCookie(config('session.cookie'), session()->getId());
    }

    private function offer(string $request): string
    {
        return app(AllocateCollector::class)->allocate($this->admin, $request, $this->offerFields($request, $this->collector, $this->place));
    }

    private function fails(callable $action, ?string $field = null, int $status = 409): void
    {
        try {
            $action();
            $this->fail('Unsafe mutation succeeded.');
        } catch (ValidationException $e) {
            $this->assertNotNull($field);
            $this->assertArrayHasKey($field, $e->errors());
        } catch (HttpException $e) {
            $this->assertNull($field);
            $this->assertSame($status, $e->getStatusCode());
        }
    }

    private function directRejection(callable $action): void
    {
        try {
            DB::transaction($action);
            $this->fail('Invalid direct database mutation succeeded.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    public function test_offer_accept_private_views_and_consumer_projection_leave_request_review_and_custody_unchanged(): void
    {
        $item = $this->device($this->owner, 'standalone_part', 'ssd', ['identifier' => 'PRIVATE-SERIAL-FIXTURE', 'identifier_type' => 'serial']);
        $request = $this->readyRequest($this->admin, $this->owner, [$item->subject_id]);
        $before = DB::table('custody_current')->get()->toJson();
        $fields = $this->offerFields($request, $this->collector, $this->place);
        $id = app(AllocateCollector::class)->allocate($this->admin, $request, $fields);
        $this->assertSame($id, app(AllocateCollector::class)->allocate($this->admin, $request, $fields));
        $this->signIn($this->collector);
        $page = $this->get('/collector/assignments/'.$id)->assertOk()->assertSee('Offer awaiting acknowledgment')->assertHeader('Cache-Control', 'no-store, private');
        foreach (['Synthetic contact only', 'Synthetic landmark only', 'PRIVATE-SERIAL-FIXTURE', $this->owner->email, 'Private synthetic allocation reason', $this->owner->display_name] as $private) {
            $page->assertDontSee($private);
        }
        $response = $this->responseFields($id);
        app(AllocateCollector::class)->respond($this->collector, $id, $response);
        $this->assertSame($id, app(AllocateCollector::class)->respond($this->collector, $id, $response));
        $this->get('/collector/assignments/'.$id)->assertOk()->assertSee('Accepted — not scheduled')->assertSee('Synthetic contact only')->assertSee('Synthetic landmark only')->assertDontSee('PRIVATE-SERIAL-FIXTURE');
        $this->assertSame('ready', app(Review::class)->state(DB::table('requests')->where('id', $request)->first()));
        $this->assertDatabaseHas('requests', ['id' => $request, 'version' => 2, 'state' => 'SUBMITTED']);
        $this->assertSame($before, DB::table('custody_current')->get()->toJson());
        $this->assertDatabaseHas('request_items', ['request_id' => $request, 'active' => true]);
        $this->signIn($this->owner);
        $this->get('/consumer')->assertOk()->assertSee('Accepted — not scheduled')->assertHeader('Cache-Control', 'no-store, private')->assertDontSee('Private synthetic allocation reason');
        $this->get('/requests/'.$request)->assertOk()->assertSee('Accepted — not scheduled')->assertDontSee('Private synthetic allocation reason')->assertDontSee('Private synthetic response reason');
    }

    public function test_decline_withdraw_and_reassignment_require_new_acknowledgment_and_preserve_historical_retries(): void
    {
        $request = $this->readyRequest($this->admin, $this->owner);
        $offer = $this->offerFields($request, $this->collector, $this->place);
        $first = app(AllocateCollector::class)->allocate($this->admin, $request, $offer);
        $decline = $this->responseFields($first, 'decline');
        app(AllocateCollector::class)->respond($this->collector, $first, $decline);
        $this->assertDatabaseHas('assignments', ['id' => $first, 'state' => 'declined', 'active' => false]);
        $second = $this->offer($request);
        $accept = $this->responseFields($second);
        app(AllocateCollector::class)->respond($this->collector, $second, $accept);
        $replacement = $this->offerFields($request, $this->collector, $this->place) + ['assignment_id' => $second, 'expected_version' => 2];
        $replacement['operation'] = 'reassign';
        $third = app(AllocateCollector::class)->allocate($this->admin, $request, $replacement);
        $this->assertDatabaseHas('assignments', ['id' => $second, 'state' => 'replaced', 'active' => false]);
        $this->assertDatabaseHas('assignments', ['id' => $third, 'state' => 'offered', 'active' => true, 'accepted_at' => null]);
        $this->assertSame($third, app(AllocateCollector::class)->allocate($this->admin, $request, $replacement));
        $this->assertSame($first, app(AllocateCollector::class)->allocate($this->admin, $request, $offer));
        $this->assertSame($first, app(AllocateCollector::class)->respond($this->collector, $first, $decline));
        $this->assertSame($second, app(AllocateCollector::class)->respond($this->collector, $second, $accept));
        $changed = $replacement;
        $changed['reason'] = 'Different payload';
        $this->fails(fn () => app(AllocateCollector::class)->allocate($this->admin, $request, $changed));
        $stale = $replacement;
        $stale['idempotency_key'] = Str::uuid()->toString();
        $this->fails(fn () => app(AllocateCollector::class)->allocate($this->admin, $request, $stale));
        $this->fails(fn () => app(AllocateCollector::class)->respond($this->collector, $second, $this->responseFields($second)));
        app(AllocateCollector::class)->allocate($this->admin, $request, ['operation' => 'withdraw', 'assignment_id' => $third, 'expected_version' => 1, 'reason' => 'Synthetic withdrawal', 'idempotency_key' => Str::uuid()->toString()]);
        $this->assertSame(0, DB::table('assignments')->where('active', true)->count());
        $this->assertDatabaseHas('request_items', ['request_id' => $request, 'active' => true]);
    }

    public function test_partial_cancellation_invalidates_the_whole_assignment_and_only_releases_selected_reservations(): void
    {
        $items = [$this->device($this->owner)->subject_id, $this->device($this->owner, 'standalone_part', 'hdd')->subject_id];
        $request = $this->readyRequest($this->admin, $this->owner, $items);
        $id = $this->offer($request);
        app(AllocateCollector::class)->respond($this->collector, $id, $this->responseFields($id));
        $before = DB::table('custody_current')->get()->toJson();
        $change = ['operation' => 'cancel', 'items' => [$items[0]], 'expected_version' => 2, 'idempotency_key' => Str::uuid()->toString(), 'reason' => 'Synthetic partial cancellation'];
        app(CollectionRequests::class)->change($this->owner, $request, $change);
        app(CollectionRequests::class)->change($this->owner, $request, $change);
        $this->assertDatabaseHas('assignments', ['id' => $id, 'state' => 'invalidated', 'active' => false]);
        $this->assertDatabaseHas('request_items', ['item_id' => $items[0], 'active' => false, 'outcome' => 'cancelled']);
        $this->assertDatabaseHas('request_items', ['item_id' => $items[1], 'active' => true, 'outcome' => 'outstanding']);
        $this->signIn($this->collector);
        $this->get('/collector/assignments/'.$id)->assertOk()->assertDontSee('Synthetic contact only')->assertDontSee('Synthetic landmark only');
        $this->assertSame($before, DB::table('custody_current')->get()->toJson());
        $this->ready($this->admin, $request);
        $this->offer($request);
        $this->assertSame(1, DB::table('assignments')->where('active', true)->count());
    }

    public function test_date_observation_and_fresh_review_changes_invalidate_without_releasing_or_transferring_items(): void
    {
        foreach (['date', 'observation', 'hold', 'ready'] as $change) {
            $item = $this->device($this->owner);
            $request = $this->readyRequest($this->admin, $this->owner, [$item->subject_id]);
            $id = $this->offer($request);
            if ($change === 'date') {
                app(CollectionRequests::class)->change($this->owner, $request, ['operation' => 'date', 'items' => [$item->subject_id], 'preferred_date' => now('Africa/Nairobi')->addDays(3)->toDateString(), 'expected_version' => 2, 'idempotency_key' => Str::uuid()->toString(), 'reason' => 'Synthetic date']);
            } elseif ($change === 'observation') {
                $fields = $item->observations()->first()->answers;
                unset($fields['category'], $fields['subtype'], $fields['model'], $fields['identifier']);
                $fields += ['expected_version' => 1, 'idempotency_key' => Str::uuid()->toString()];
                $fields['safety_concern'] = 'UNKNOWN';
                app(RegisterDevice::class)->revise($this->owner, $item, $fields);
            } else {
                app(ReviewRequest::class)->decide($this->admin, $request, ['decision' => $change, 'expected_version' => 2, 'reason' => 'Synthetic decision', 'idempotency_key' => Str::uuid()->toString()]);
            }
            $this->assertDatabaseHas('assignments', ['id' => $id, 'state' => 'invalidated', 'active' => false]);
            $this->assertDatabaseHas('request_items', ['item_id' => $item->subject_id, 'active' => true]);
            $this->assertSame(1, (int) DB::table('custody_current')->where('subject_id', $item->subject_id)->value('version'));
        }
    }

    public function test_out_of_band_drift_blocks_sensitive_reads_and_acceptance_without_writing_on_get(): void
    {
        foreach (['area', 'account', 'date', 'capability', 'membership', 'reservation'] as $drift) {
            DB::beginTransaction();
            try {
                $request = $this->readyRequest($this->admin, $this->owner);
                $id = $this->offer($request);
                app(AllocateCollector::class)->respond($this->collector, $id, $this->responseFields($id));
                match ($drift) {
                    'area' => DB::table('service_areas')->update(['active' => false]),
                    'account' => DB::table('users')->where('id', $this->owner->id)->update(['status' => 'suspended']),
                    'date' => $this->travel(3)->days(),
                    'capability' => DB::table('place_categories')->where('place_id', $this->place)->where('subtype', 'ssd')->delete(),
                    'membership' => DB::table('memberships')->where('user_id', $this->collector->id)->increment('version'),
                    'reservation' => DB::table('request_items')->where('request_id', $request)->update(['active' => false, 'outcome' => 'cancelled']),
                };
                $before = [DB::table('assignments')->get()->toJson(), DB::table('domain_events')->count(), DB::table('notification_outbox')->count()];
                $this->signIn($this->collector->fresh());
                $this->get('/collector/assignments/'.$id)->assertOk()->assertDontSee('Synthetic contact only')->assertDontSee('Synthetic landmark only');
                $this->assertSame($before, [DB::table('assignments')->get()->toJson(), DB::table('domain_events')->count(), DB::table('notification_outbox')->count()]);
                $this->assertFalse(app(Eligibility::class)->current(DB::table('assignments')->where('id', $id)->first()));
            } finally {
                $this->travelBack();
                DB::rollBack();
            }
        }
    }

    public function test_cross_account_roles_suspension_and_old_sessions_cannot_access_or_acknowledge(): void
    {
        $request = $this->readyRequest($this->admin, $this->owner);
        $id = $this->offer($request);
        $other = User::factory()->create();
        $other->membership()->update(['role' => Role::Collector, 'organization_id' => $this->collector->membership->organization_id]);
        $other->refresh();
        $this->signIn($other);
        $this->get('/collector/assignments/'.$id)->assertNotFound();
        $this->post('/collector/assignments/'.$id, $this->responseFields($id))->assertNotFound();
        $this->get('/admin/assignments')->assertForbidden();
        foreach ([Role::Consumer, Role::Processor] as $role) {
            $other->membership()->update(['organization_id' => null, 'role' => $role]);
            $this->signIn($other->fresh());
            $this->get('/collector/assignments/'.$id)->assertForbidden();
            $this->post('/admin/collectors/'.$this->owner->id, [])->assertForbidden();
        }
        $this->signIn($this->collector);
        $this->get('/items/'.$this->device($this->owner)->subject_id)->assertForbidden();
        DB::table('organizations')->where('id', $this->collector->membership->organization_id)->update(['review_status' => 'suspended']);
        $this->get('/collector/assignments/'.$id)->assertForbidden();
        $this->fails(fn () => app(AllocateCollector::class)->respond($this->collector, $id, $this->responseFields($id)), null, 403);
        DB::table('organizations')->where('id', $this->collector->membership->organization_id)->update(['review_status' => 'approved']);
        $old = $this->collector;
        app(ManageCollector::class)->save($this->admin, $old->id, ['operation' => 'deactivate', 'expected_version' => $old->membership->version, 'reason' => 'Synthetic revocation', 'idempotency_key' => Str::uuid()->toString()]);
        $this->assertDatabaseHas('assignments', ['id' => $id, 'state' => 'invalidated']);
        $this->get('/collector/assignments/'.$id)->assertRedirect('/login');
        $this->fails(fn () => app(AllocateCollector::class)->respond($old, $id, $this->responseFields($id)), null, 403);
        $this->assertDatabaseHas('request_items', ['request_id' => $request, 'active' => true]);
    }

    public function test_exact_part_capabilities_invalid_destinations_held_and_dropoff_jobs_are_rejected(): void
    {
        foreach (['hdd', 'ssd', 'ict_cooling_fan'] as $part) {
            $item = $this->device($this->owner, 'standalone_part', $part);
            $request = $this->readyRequest($this->admin, $this->owner, [$item->subject_id]);
            $id = $this->offer($request);
            $this->assertTrue(app(Eligibility::class)->current(DB::table('assignments')->where('id', $id)->first()));
        }
        $drop = $this->readyRequest($this->admin, $this->owner, [], 'drop_off');
        $this->fails(fn () => $this->offer($drop), 'eligibility');
        $request = $this->readyRequest($this->admin, $this->owner);
        app(ReviewRequest::class)->decide($this->admin, $request, ['decision' => 'hold', 'reason' => 'Synthetic hold', 'expected_version' => 2, 'idempotency_key' => Str::uuid()->toString()]);
        $this->fails(fn () => $this->offer($request), 'eligibility');
        $this->ready($this->admin, $request);
        $org = (array) DB::table('organizations')->first();
        $org['id'] = Str::uuid()->toString();
        $org['code'] = 'synthetic-other-scope';
        $org['name'] = 'Synthetic other scope';
        DB::table('organizations')->insert($org);
        $place = (array) DB::table('places')->where('id', $this->place)->first();
        $place['id'] = Str::uuid()->toString();
        $place['organization_id'] = $org['id'];
        $place['code'] = 'synthetic-incompatible';
        $place['name'] = 'Synthetic incompatible';
        DB::table('places')->insert($place);
        $category = DB::table('categories')->where('code', 'laptop')->value('id');
        DB::table('place_categories')->insert(['place_id' => $place['id'], 'category_id' => $category, 'subtype' => '']);
        $this->ready($this->admin, $request);
        $fields = $this->offerFields($request, $this->collector, $place['id']);
        $this->fails(fn () => app(AllocateCollector::class)->allocate($this->admin, $request, $fields), 'facility_ref');
        $other = User::factory()->create();
        $other->membership()->update(['role' => Role::Collector, 'organization_id' => $org['id']]);
        $fields = $this->offerFields($request, $other->fresh(), $this->place);
        $this->fails(fn () => app(AllocateCollector::class)->allocate($this->admin, $request, $fields), 'membership_ref');
        DB::table('places')->where('id', $place['id'])->update(['kind' => 'depot']);
        $this->ready($this->admin, $request);
        $fields = $this->offerFields($request, $this->collector, $place['id']);
        $this->fails(fn () => app(AllocateCollector::class)->allocate($this->admin, $request, $fields), 'facility_ref');
    }

    public function test_collector_provisioning_is_private_versioned_idempotent_and_cannot_promote_consumers(): void
    {
        $org = DB::table('organizations')->where('id', $this->collector->membership->organization_id)->first();
        $fields = ['operation' => 'create', 'display_name' => 'Synthetic new Collector', 'email' => 'COLLECTOR-NEW@example.test', 'password' => 'SyntheticPrivate123', 'password_confirmation' => 'SyntheticPrivate123', 'organization_ref' => $org->id.':'.$org->version, 'reason' => 'Private synthetic access reason', 'idempotency_key' => Str::uuid()->toString()];
        $userId = app(ManageCollector::class)->save($this->admin, null, $fields);
        $this->assertSame($userId, app(ManageCollector::class)->save($this->admin, null, $fields));
        $user = User::findOrFail($userId);
        $this->assertTrue(Hash::check($fields['password'], $user->password_hash));
        $this->assertSame('collector-new@example.test', $user->email);
        $this->assertSame($org->id, $user->membership->organization_id);
        $this->assertStringNotContainsString($fields['password'], DB::table('commands')->get()->toJson());
        $this->assertStringNotContainsString($fields['password'], DB::table('domain_events')->get()->toJson());
        $edit = ['operation' => 'link', 'organization_ref' => $org->id.':'.$org->version, 'expected_version' => 1, 'reason' => 'Synthetic edit', 'idempotency_key' => Str::uuid()->toString()];
        $this->fails(fn () => app(ManageCollector::class)->save($this->admin, $this->owner->id, $edit), null, 404);
        app(ManageCollector::class)->save($this->admin, $userId, $edit);
        $this->assertSame(2, $user->fresh()->session_version);
        $edit['idempotency_key'] = Str::uuid()->toString();
        $this->fails(fn () => app(ManageCollector::class)->save($this->admin, $userId, $edit));
        $duplicate = $fields;
        $duplicate['idempotency_key'] = Str::uuid()->toString();
        $this->fails(fn () => app(ManageCollector::class)->save($this->admin, null, $duplicate), 'email');
        $this->assertSame(Role::Consumer, $this->owner->fresh()->role());
        $this->directRejection(fn () => $this->owner->membership()->update(['organization_id' => $org->id]));
    }

    public function test_protected_payloads_error_associations_and_safe_flash_policy(): void
    {
        $request = $this->readyRequest($this->admin, $this->owner);
        $fields = $this->offerFields($request, $this->collector, $this->place);
        $this->signIn($this->admin);
        foreach (['collector_id', 'operator_id', 'state', 'active', 'accepted_at', 'eligibility_snapshot', 'custody'] as $key) {
            foreach ([null, []] as $value) {
                $this->post('/admin/assignments/'.$request, $fields + [$key => $value])->assertRedirect('/admin/assignments/'.$request)->assertSessionHasErrors('submission');
            }
        }
        $invalid = $fields;
        $invalid['membership_ref'] = 'invalid';
        $this->post('/admin/assignments/'.$request, $invalid)->assertSessionHasErrors('membership_ref');
        $this->assertNull(session()->getOldInput('reason'));
        $this->assertSame($fields['idempotency_key'], session()->getOldInput('idempotency_key'));
        $this->get('/admin/assignments/'.$request)->assertOk()->assertSee('aria-invalid="true"', false)->assertSee('membership_ref-error')->assertSee('href="#membership_ref"', false);
        $this->post('/admin/collectors', ['operation' => 'create', 'password' => 'PrivateFlashFixture', 'password_confirmation' => 'PrivateFlashFixture', 'role' => null])->assertRedirect('/admin/collectors/create')->assertSessionHasErrors('submission');
        $this->assertNull(session()->getOldInput('password'));
        $this->assertNull(session()->getOldInput('password_confirmation'));
    }

    public function test_database_constraints_and_immutable_assignment_identity_history(): void
    {
        $request = $this->readyRequest($this->admin, $this->owner);
        $id = $this->offer($request);
        $row = (array) DB::table('assignments')->where('id', $id)->first();
        $this->directRejection(fn () => DB::table('assignments')->insert(array_replace($row, ['id' => Str::uuid()->toString()])));
        $this->directRejection(fn () => DB::table('assignments')->where('id', $id)->update(['collector_id' => $this->owner->id, 'version' => 2]));
        $this->directRejection(fn () => DB::table('assignments')->where('id', $id)->update(['state' => 'accepted', 'version' => 2]));
        $this->directRejection(fn () => DB::table('assignments')->where('id', $id)->delete());
        $this->directRejection(fn () => DB::table('assignment_revisions')->where('assignment_id', $id)->delete());
        app(AllocateCollector::class)->respond($this->collector, $id, $this->responseFields($id, 'decline'));
        $this->directRejection(fn () => DB::table('assignments')->where('id', $id)->update(['state' => 'offered', 'active' => true, 'version' => 3]));
        $this->offer($request);
        $this->assertSame(1, DB::table('assignments')->where('active', true)->count());
    }

    public function test_injected_outbox_failure_rolls_back_offer_and_reassignment_atomically(): void
    {
        $request = $this->readyRequest($this->admin, $this->owner);
        $id = $this->offer($request);
        $before = [];
        foreach (['assignments', 'assignment_revisions', 'commands', 'domain_events', 'notification_outbox', 'custody_current', 'request_items'] as $table) {
            $before[$table] = DB::table($table)->get()->toJson();
        }
        DB::unprepared("CREATE FUNCTION fail_assignment_notice() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'synthetic assignment rollback'; END; $$; CREATE TRIGGER fail_assignment_notice BEFORE INSERT ON notification_outbox FOR EACH ROW EXECUTE FUNCTION fail_assignment_notice();");
        $fields = $this->offerFields($request, $this->collector, $this->place) + ['assignment_id' => $id, 'expected_version' => 1];
        $fields['operation'] = 'reassign';
        try {
            app(AllocateCollector::class)->allocate($this->admin, $request, $fields);
            $this->fail('Injected failure was suppressed.');
        } catch (QueryException) {
            foreach ($before as $table => $rows) {
                $this->assertSame($rows, DB::table($table)->get()->toJson(), $table);
            }
        }
    }

    public function test_assignment_notices_are_retry_safe_recipient_scoped_safe_after_revocation_and_leave_historical_outbox_untouched(): void
    {
        $request = $this->readyRequest($this->admin, $this->owner);
        $historical = DB::table('notification_outbox')->get()->toJson();
        $ids = DB::table('notification_outbox')->pluck('id');
        $id = $this->offer($request);
        app(AllocateCollector::class)->respond($this->collector, $id, $this->responseFields($id));
        $this->assertSame(6, app(Notices::class)->deliver());
        $this->assertSame(0, app(Notices::class)->deliver());
        $this->assertSame(6, DB::table('assignment_notifications')->count());
        $this->assertSame($historical, DB::table('notification_outbox')->whereIn('id', $ids)->get()->toJson());
        $this->signIn($this->owner);
        $this->get('/assignment-notices')->assertOk()->assertDontSee('Synthetic contact only')->assertDontSee('Private synthetic response reason');
        $own = DB::table('assignment_notifications')->where('recipient_id', $this->owner->id)->value('id');
        $other = DB::table('assignment_notifications')->where('recipient_id', $this->collector->id)->value('id');
        $this->post('/assignment-notices/'.$other.'/read')->assertNotFound();
        $this->post('/assignment-notices/'.$own.'/read')->assertRedirect('/assignment-notices');
        $read = DB::table('assignment_notifications')->where('id', $own)->value('read_at');
        $this->post('/assignment-notices/'.$own.'/read')->assertRedirect('/assignment-notices');
        $this->assertSame($read, DB::table('assignment_notifications')->where('id', $own)->value('read_at'));
        app(AllocateCollector::class)->allocate($this->admin, $request, ['operation' => 'withdraw', 'assignment_id' => $id, 'expected_version' => 2, 'reason' => 'Private synthetic withdrawal', 'idempotency_key' => Str::uuid()->toString()]);
        $this->signIn($this->collector);
        $this->get('/assignment-notices')->assertOk()->assertDontSee('Synthetic contact only');
        $this->get('/collector/assignments/'.$id)->assertOk()->assertDontSee('Synthetic contact only');
        $this->assertSame($historical, DB::table('notification_outbox')->whereIn('id', $ids)->get()->toJson());
    }

    public function test_directory_suspension_invalidates_live_assignments_and_retains_reservations_and_history(): void
    {
        $request = $this->readyRequest($this->admin, $this->owner);
        $id = $this->offer($request);
        app(AllocateCollector::class)->respond($this->collector, $id, $this->responseFields($id));
        $org = DB::table('organizations')->where('id', $this->collector->membership->organization_id)->first();
        $before = DB::table('custody_current')->get()->toJson();
        app(ManageDirectory::class)->save($this->admin, 'organization', $org->id, ['name' => $org->name, 'kind' => $org->kind, 'review_status' => 'suspended', 'contact' => 'Synthetic directory contact', 'reason' => 'Synthetic suspension', 'expected_version' => $org->version, 'idempotency_key' => Str::uuid()->toString(), 'duplicate_review_ack' => '1']);
        $this->assertDatabaseHas('assignments', ['id' => $id, 'state' => 'invalidated', 'active' => false]);
        $this->assertDatabaseHas('requests', ['id' => $request, 'review_state' => 'stale']);
        $this->assertDatabaseHas('request_items', ['request_id' => $request, 'active' => true]);
        $this->assertSame($before, DB::table('custody_current')->get()->toJson());
        $this->assertSame(3, DB::table('assignment_revisions')->where('assignment_id', $id)->count());
    }

    public function test_linking_a_different_operator_removes_old_work_scope_and_cannot_replay_an_old_acknowledgment(): void
    {
        $request = $this->readyRequest($this->admin, $this->owner);
        $id = $this->offer($request);
        $response = $this->responseFields($id);
        app(AllocateCollector::class)->respond($this->collector, $id, $response);
        $org = (array) DB::table('organizations')->where('id', $this->collector->membership->organization_id)->first();
        $org['id'] = Str::uuid()->toString();
        $org['code'] = 'synthetic-new-collector-scope';
        $org['name'] = 'Synthetic new Collector scope';
        DB::table('organizations')->insert($org);
        $oldSession = $this->collector->session_version;
        app(ManageCollector::class)->save($this->admin, $this->collector->id, ['operation' => 'link', 'organization_ref' => $org['id'].':'.$org['version'], 'expected_version' => $this->collector->membership->version, 'reason' => 'Synthetic reassignment of access', 'idempotency_key' => Str::uuid()->toString()]);
        $this->collector->refresh();
        $this->assertSame($oldSession + 1, $this->collector->session_version);
        $this->signIn($this->collector);
        $this->get('/collector/assignments/'.$id)->assertNotFound();
        $this->fails(fn () => app(AllocateCollector::class)->respond($this->collector, $id, $response), null, 403);
        $this->assertDatabaseHas('assignments', ['id' => $id, 'state' => 'invalidated']);
        $this->get('/assignment-notices')->assertOk()->assertDontSee('Synthetic contact only');
    }

    public function test_stale_selected_versions_and_offer_accept_failure_paths_cannot_create_partial_records(): void
    {
        $request = $this->readyRequest($this->admin, $this->owner);
        $fields = $this->offerFields($request, $this->collector, $this->place);
        $fields['membership_ref'] = $this->collector->membership->id.':1';
        $this->fails(fn () => app(AllocateCollector::class)->allocate($this->admin, $request, $fields));
        $fields = $this->offerFields($request, $this->collector, $this->place);
        $fields['facility_ref'] = $this->place.':99';
        $this->fails(fn () => app(AllocateCollector::class)->allocate($this->admin, $request, $fields));
        $before = DB::table('commands')->count();
        DB::unprepared("CREATE FUNCTION fail_new_assignment_notice() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'synthetic new assignment rollback'; END; $$; CREATE TRIGGER fail_new_assignment_notice BEFORE INSERT ON notification_outbox FOR EACH ROW EXECUTE FUNCTION fail_new_assignment_notice();");
        try {
            $this->offer($request);
            $this->fail('Expected offer rollback.');
        } catch (QueryException) {
            $this->assertDatabaseCount('assignments', 0);
            $this->assertDatabaseCount('assignment_revisions', 0);
            $this->assertSame($before, DB::table('commands')->count());
        }
        DB::unprepared('DROP TRIGGER fail_new_assignment_notice ON notification_outbox');
        $id = $this->offer($request);
        DB::unprepared('CREATE TRIGGER fail_new_assignment_notice BEFORE INSERT ON notification_outbox FOR EACH ROW EXECUTE FUNCTION fail_new_assignment_notice()');
        try {
            app(AllocateCollector::class)->respond($this->collector, $id, $this->responseFields($id));
            $this->fail('Expected acceptance rollback.');
        } catch (QueryException) {
            $this->assertDatabaseHas('assignments', ['id' => $id, 'state' => 'offered', 'version' => 1, 'accepted_at' => null]);
            $this->assertSame(1, DB::table('assignment_revisions')->count());
        }
    }

    public function test_notification_worker_retry_and_failure_do_not_duplicate_or_lose_notices(): void
    {
        $request = $this->readyRequest($this->admin, $this->owner);
        $this->offer($request);
        $this->assertSame(3, app(Notices::class)->deliver());
        $ids = DB::table('assignment_notifications')->pluck('outbox_id');
        DB::table('notification_outbox')->whereIn('id', $ids)->update(['state' => 'pending']);
        $this->assertSame(3, app(Notices::class)->deliver());
        $this->assertDatabaseCount('assignment_notifications', 3);
        DB::table('notification_outbox')->whereIn('id', $ids)->update(['state' => 'pending']);
        $before = DB::table('notification_outbox')->get()->toJson();
        DB::unprepared("CREATE FUNCTION fail_notice_delivery() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'synthetic delivery rollback'; END; $$; CREATE TRIGGER fail_notice_delivery BEFORE UPDATE ON notification_outbox FOR EACH ROW EXECUTE FUNCTION fail_notice_delivery();");
        try {
            app(Notices::class)->deliver();
            $this->fail('Expected delivery rollback.');
        } catch (QueryException) {
            $this->assertSame($before, DB::table('notification_outbox')->get()->toJson());
            $this->assertDatabaseCount('assignment_notifications', 3);
        }
    }
}
