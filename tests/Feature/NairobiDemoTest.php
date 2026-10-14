<?php

namespace Tests\Feature;

use App\Actions\AllocateCollector;
use App\Actions\CollectionRequests;
use App\Actions\ManageDirectory;
use App\Actions\ReviewRequest;
use App\Enums\Role;
use App\Models\User;
use App\Support\Collection\Review;
use App\Support\Demo\DemoSetupException;
use App\Support\Demo\NairobiDataset;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\CollectionFixtures;
use Tests\TestCase;

class NairobiDemoTest extends TestCase
{
    use CollectionFixtures, DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1]);
        Storage::fake('directory_evidence');
        $this->directory();
        $this->admin = User::factory()->create();
        $this->admin->membership()->update(['role' => Role::Administrator]);
        $this->admin->refresh();
    }

    private function passwords(): array
    {
        // Random isolated fixtures, never shared development credentials.
        return array_fill_keys(array_keys(NairobiDataset::ACCOUNTS), Str::random(32));
    }

    private function createFixture(): array
    {
        return app(NairobiDataset::class)->apply($this->admin, $this->passwords(), UploadedFile::fake()->image('isolated-source-fixture.png'));
    }

    private function fingerprint(): array
    {
        $result = [];
        foreach (DB::select('SELECT tablename FROM pg_tables WHERE schemaname = current_schema() ORDER BY tablename') as $table) {
            $rows = DB::table($table->tablename)->get()->map(fn ($row) => hash('sha256', json_encode($row, JSON_THROW_ON_ERROR)))->all();
            sort($rows);
            $result[$table->tablename] = $rows;
        }

        return $result;
    }

    public function test_bounded_setup_then_ready_offer_acceptance_without_custody_or_delivery(): void
    {
        $plan = app(NairobiDataset::class)->plan($this->admin);
        $this->assertSame([], $plan['conflicts']);
        $this->assertSame(array_fill_keys(array_keys(NairobiDataset::ACCOUNTS), null), $plan['accounts']);
        $before = $this->fingerprint();
        $data = $this->createFixture();
        $this->assertDatabaseCount('users', 7);
        $this->assertDatabaseCount('organizations', 3);
        $this->assertDatabaseCount('places', 5);
        $this->assertDatabaseCount('items', 3);
        $this->assertDatabaseCount('storage_assets', 2);
        $this->assertDatabaseCount('requests', 2);
        $this->assertDatabaseCount('request_items', 3);
        $this->assertDatabaseCount('request_reviews', 2);
        $this->assertDatabaseCount('assignments', 0);
        $this->assertDatabaseCount('assignment_notifications', 0);
        $this->assertSame(1, User::all()->filter(fn ($u) => $u->role() === Role::Administrator)->count());
        $this->assertSame(3, User::all()->filter(fn ($u) => $u->role() === Role::Collector)->count());
        $this->assertNull(User::findOrFail($data['accounts']['processor-1'])->membership->organization_id);
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, array_values(array_intersect($rows, $this->fingerprint()[$table])), 'Existing rows changed in '.$table);
        }
        foreach ($data['places'] as $id) {
            $this->assertDatabaseHas('places', ['id' => $id, 'synthetic' => true, 'kind' => 'collection_point', 'active' => true, 'review_status' => 'approved']);
        }
        foreach (['ssd-1', 'hdd-1'] as $key) {
            $this->assertDatabaseHas('items', ['subject_id' => $data['devices'][$key], 'data_status' => 'YES', 'identifier_hmac' => null]);
        }
        foreach (DB::table('requests')->get() as $r) {
            $this->assertSame('ready', app(Review::class)->state($r));
            $this->assertSame([], app(Review::class)->assess($r)['blockers']);
        }
        $custody = DB::table('custody_current')->orderBy('subject_id')->get()->all();
        $r = DB::table('requests')->where('id', $data['requests']['consumer-1'])->first();
        $collector = User::findOrFail($data['accounts']['collector-2']);
        $place = DB::table('places')->where('id', $data['places']['weee'])->first();
        $id = app(AllocateCollector::class)->allocate($this->admin, $r->id, ['operation' => 'offer', 'membership_ref' => $collector->membership->id.':'.$collector->membership->version, 'facility_ref' => $place->id.':'.$place->version, 'expected_request_version' => $r->version, 'expected_review_id' => $r->review_id, 'reason' => 'Synthetic demo test offer.', 'idempotency_key' => Str::uuid()->toString()]);
        app(AllocateCollector::class)->respond($collector, $id, ['operation' => 'accept', 'expected_version' => 1, 'idempotency_key' => Str::uuid()->toString()]);
        $this->assertDatabaseHas('assignments', ['id' => $id, 'state' => 'accepted', 'active' => true]);
        $this->assertEquals($custody, DB::table('custody_current')->orderBy('subject_id')->get()->all());
        $this->assertSame(0, DB::table('notification_outbox')->where('state', '!=', 'pending')->count());
        $owner = User::findOrFail($data['accounts']['consumer-1']);
        $this->actingAs($owner)->withSession(['auth_session_version' => $owner->session_version])->get('/requests/'.$r->id)->assertOk()->assertSee('Accepted — not scheduled')->assertDontSee('Synthetic demo test offer.');
        $this->actingAs($owner)->get('/collection-points')->assertOk()->assertSee('simulated Enviroserve reference')->assertSee('Synthetic demonstration');
    }

    public function test_repeats_preserve_all_rows_passwords_edits_history_pending_notices_and_expired_requests(): void
    {
        $data = $this->createFixture();
        $user = User::findOrFail($data['accounts']['consumer-1']);
        $user->update(['display_name' => 'Edited demo name', 'email' => 'edited-demo@example.test', 'password_hash' => Str::random(32)]);
        $org = DB::table('organizations')->where('id', $data['organizations']['weee'])->first();
        app(ManageDirectory::class)->save($this->admin, 'organization', $org->id, ['name' => 'User-edited synthetic operator', 'kind' => $org->kind, 'review_status' => 'approved', 'contact' => 'Private synthetic edited contact', 'reason' => 'Synthetic user edit', 'idempotency_key' => Str::uuid()->toString(), 'expected_version' => $org->version, 'duplicate_review_ack' => '1']);
        $this->travel(20)->days();
        try {
            $before = $this->fingerprint();
            $files = Storage::disk('directory_evidence')->allFiles();
            $again = app(NairobiDataset::class)->apply($this->admin, [], null);
            $this->assertSame($data['accounts'], $again['accounts']);
            $this->assertSame($data['devices'], $again['devices']);
            $this->assertSame($data['requests'], $again['requests']);
            $this->assertSame($before, $this->fingerprint());
            $this->assertSame($files, Storage::disk('directory_evidence')->allFiles());
        } finally {
            $this->travelBack();
        }
    }

    public function test_another_existing_admin_reuses_stable_records_without_replaying_completed_commands(): void
    {
        $data = $this->createFixture();
        $other = User::factory()->create();
        $other->membership()->update(['role' => Role::Administrator]);
        $other->refresh();
        $before = $this->fingerprint();
        $again = app(NairobiDataset::class)->apply($other, [], null);
        foreach (['organizations', 'places', 'accounts', 'devices', 'requests'] as $group) {
            $this->assertSame($data[$group], $again[$group]);
        }
        $this->assertSame($before, $this->fingerprint());
    }

    public function test_existing_closed_work_and_its_pending_outbox_remain_byte_for_byte_unchanged(): void
    {
        $owner = User::factory()->create();
        $device = $this->device($owner);
        $id = app(CollectionRequests::class)->submit($owner, $this->requestFields([$device->subject_id]));
        app(CollectionRequests::class)->change($owner, $id, ['operation' => 'cancel', 'items' => [$device->subject_id], 'expected_version' => 1, 'reason' => 'Closed synthetic history fixture.', 'idempotency_key' => Str::uuid()->toString()]);
        $before = $this->fingerprint();
        $this->createFixture();
        $after = $this->fingerprint();
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, array_values(array_intersect($rows, $after[$table])), 'Historical row changed in '.$table);
        }
        $this->assertSame(0, DB::table('notification_outbox')->where('state', '!=', 'pending')->count());
    }

    public function test_active_work_is_reported_and_refused_before_any_fixture_or_file_write(): void
    {
        $owner = User::factory()->create();
        $device = $this->device($owner);
        $id = app(CollectionRequests::class)->submit($owner, $this->requestFields([$device->subject_id]));
        $plan = app(NairobiDataset::class)->plan($this->admin);
        $this->assertSame([$id], $plan['conflicts']);
        $before = $this->fingerprint();
        try {
            $this->createFixture();
            $this->fail('Active-work conflict must refuse apply.');
        } catch (DemoSetupException $e) {
            $this->assertStringContainsString('Existing active work', $e->getMessage());
        }
        $this->assertSame($before, $this->fingerprint());
        $this->assertSame([], Storage::disk('directory_evidence')->allFiles());
        $this->assertDatabaseHas('request_items', ['request_id' => $id, 'active' => true]);
    }

    public function test_exact_authorized_reviews_change_only_versions_and_history_with_reservations_custody_and_old_rows_retained(): void
    {
        $owner = User::factory()->create();
        $device = $this->device($owner);
        $id = app(CollectionRequests::class)->submit($owner, $this->requestFields([$device->subject_id]));
        $request = (array) DB::table('requests')->where('id', $id)->first();
        $before = $this->fingerprint();
        $items = DB::table('request_items')->where('request_id', $id)->orderBy('id')->get()->all();
        $custody = DB::table('custody_current')->where('subject_id', $device->subject_id)->first();
        $data = app(NairobiDataset::class)->apply($this->admin, $this->passwords(), UploadedFile::fake()->image('isolated-source-fixture.png'), [$id => $request['version']]);
        $request['version'] += 4;
        $request['review_state'] = 'stale';
        $this->assertSame($request, (array) DB::table('requests')->where('id', $id)->first());
        $this->assertEquals($items, DB::table('request_items')->where('request_id', $id)->orderBy('id')->get()->all());
        $this->assertEquals($custody, DB::table('custody_current')->where('subject_id', $device->subject_id)->first());
        $after = $this->fingerprint();
        foreach ($before as $table => $rows) {
            if ($table !== 'requests') {
                $this->assertSame($rows, array_values(array_intersect($rows, $after[$table])), 'Existing row changed in '.$table);
            }
        }
        $this->assertSame(4, DB::table('domain_events')->where('aggregate_id', $id)->where('type', 'request_directory_changed')->count());
        $this->assertDatabaseCount('request_reviews', 2);
        $this->assertDatabaseCount('assignments', 0);
        $this->assertSame(1, DB::table('domain_events')->where('type', 'nairobi_demo_review_changes_authorized')->count());
        foreach ($data['requests'] as $newId) {
            $this->assertDatabaseHas('requests', ['id' => $newId, 'review_state' => 'ready']);
        }
        $repeat = $this->fingerprint();
        app(NairobiDataset::class)->apply($this->admin, [], null, [$id => $request['version'] - 4]);
        $this->assertSame($repeat, $this->fingerprint());
    }

    public function test_partial_extra_stale_or_new_live_work_is_never_permitted_by_review_authorization(): void
    {
        $owner = User::factory()->create();
        $first = $this->device($owner);
        $id = app(CollectionRequests::class)->submit($owner, $this->requestFields([$first->subject_id]));
        $dataset = app(NairobiDataset::class);
        $before = $this->fingerprint();
        foreach ([[], [$id => 99], [$id => 1, Str::uuid()->toString() => 1]] as $allowed) {
            try {
                $dataset->apply($this->admin, $this->passwords(), UploadedFile::fake()->image('isolated-source-fixture.png'), $allowed);
                $this->fail('Unapproved/stale set must fail.');
            } catch (DemoSetupException) {
                $this->assertSame($before, $this->fingerprint());
            }
        }
        $second = $this->device($owner);
        app(CollectionRequests::class)->submit($owner, $this->requestFields([$second->subject_id]));
        $before = $this->fingerprint();
        try {
            $dataset->apply($this->admin, $this->passwords(), UploadedFile::fake()->image('isolated-source-fixture.png'), [$id => 1]);
            $this->fail('New live work must not expand scope.');
        } catch (DemoSetupException $e) {
            $this->assertStringContainsString('exact authorized request set', $e->getMessage());
        }
        $this->assertSame($before, $this->fingerprint());
        $this->assertSame([], Storage::disk('directory_evidence')->allFiles());
    }

    public function test_review_only_allowlist_cannot_authorize_assignment_invalidation(): void
    {
        $plan = app(NairobiDataset::class)->plan($this->admin);
        $plan['assignmentConflicts'] = [Str::uuid()->toString()];
        $this->expectException(DemoSetupException::class);
        $this->expectExceptionMessage('Active assignments');
        app(NairobiDataset::class)->assertAllowedReviewChanges($plan, []);
    }

    public function test_late_failure_rolls_back_accounts_directory_devices_requests_and_new_private_files(): void
    {
        $this->mock(ReviewRequest::class)->shouldReceive('decide')->once()->andThrow(new RuntimeException('Isolated late review failure'));
        $before = $this->fingerprint();
        try {
            $this->createFixture();
            $this->fail('Late failure must roll back.');
        } catch (RuntimeException $e) {
            $this->assertSame('Isolated late review failure', $e->getMessage());
        }
        $this->assertSame($before, $this->fingerprint());
        $this->assertSame([], Storage::disk('directory_evidence')->allFiles());
    }

    public function test_unrelated_account_collision_is_never_reused_promoted_or_password_reset(): void
    {
        User::factory()->create(['email' => strtoupper(NairobiDataset::email('collector-1'))]);
        $before = $this->fingerprint();
        try {
            app(NairobiDataset::class)->plan($this->admin);
            $this->fail('Unrelated occupied email must stop setup.');
        } catch (DemoSetupException $e) {
            $this->assertStringContainsString('unrelated', $e->getMessage());
        }
        $this->assertSame($before, $this->fingerprint());
    }

    public function test_missing_evidence_cannot_bypass_approval_and_no_non_admin_can_setup(): void
    {
        $before = $this->fingerprint();
        try {
            app(NairobiDataset::class)->apply($this->admin, $this->passwords(), null);
            $this->fail('Reference evidence required.');
        } catch (DemoSetupException $e) {
            $this->assertStringContainsString('genuine public-source', $e->getMessage());
        }
        $this->assertSame($before, $this->fingerprint());
        $this->expectException(DemoSetupException::class);
        app(NairobiDataset::class)->plan(User::factory()->create());
    }

    public function test_command_refuses_testing_production_alternate_database_urls_and_hosts_before_database_access(): void
    {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $this->artisan('app:setup-nairobi-demo', ['administrator' => $this->admin->id])->expectsOutputToContain('testing/production/URL connections are refused')->assertFailed();
        foreach (['production', 'local'] as $environment) {
            $this->app->detectEnvironment(fn () => $environment);
            $this->artisan('app:setup-nairobi-demo', ['administrator' => $this->admin->id, '--apply' => true])->assertFailed();
        }
        $original = config('database.connections.pgsql');
        foreach ([['host' => 'localhost'], ['url' => 'postgresql://invalid.invalid/ewaste_development'], ['database' => 'production'], ['search_path' => 'unexpected'], ['username' => 'postgres'], ['driver' => 'sqlite']] as $unsafe) {
            config(['database.connections.pgsql' => array_replace($original, ['database' => 'ewaste_development'], $unsafe)]);
            $this->artisan('app:setup-nairobi-demo', ['administrator' => $this->admin->id])->assertFailed();
        }
        config(['database.connections.pgsql' => $original]);
        $this->assertSame(0, $queries);
        $this->app->detectEnvironment(fn () => 'testing');
    }
}
