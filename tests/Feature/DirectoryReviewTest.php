<?php

namespace Tests\Feature;

use App\Actions\CollectionRequests;
use App\Actions\ManageDirectory;
use App\Actions\RegisterDevice;
use App\Actions\ReviewRequest;
use App\Enums\Role;
use App\Models\User;
use App\Support\Collection\DirectoryInput;
use App\Support\Collection\Review;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CollectionFixtures;
use Tests\TestCase;

class DirectoryReviewTest extends TestCase
{
    use CollectionFixtures, DatabaseTransactions;

    private User $admin;

    private User $owner;

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
        $this->owner = User::factory()->create();
        $this->signIn($this->admin);
        $org = app(ManageDirectory::class)->save($this->admin, 'organization', null, $this->orgFields(), UploadedFile::fake()->image('receiving-org.png'));
        app(ManageDirectory::class)->save($this->admin, 'place', null, $this->pointFields($org, ['kind' => 'facility']), UploadedFile::fake()->image('receiving-place.png'));
    }

    private function signIn(User $user): void
    {
        $this->actingAs($user)->withSession(['auth_session_version' => $user->session_version]);
        $this->withCookie(config('session.cookie'), session()->getId());
    }

    private function orgFields(array $extra = []): array
    {
        return array_replace(['name' => 'Synthetic reviewed operator', 'kind' => 'both', 'review_status' => 'approved', 'contact' => 'private-contact-fixture', 'reason' => 'private-staff-note-fixture', 'synthetic' => '1', 'idempotency_key' => Str::uuid()->toString()], $extra);
    }

    private function pointFields(string $org, array $extra = []): array
    {
        return array_replace(['name' => 'Synthetic intake', 'kind' => 'collection_point', 'organization_id' => $org, 'review_status' => 'approved', 'active' => '1', 'contact' => 'private-point-contact-fixture', 'latitude' => '-1.280000', 'longitude' => '36.820000', 'hours' => 'Mon–Fri 09:00–16:00 Africa/Nairobi; synthetic', 'reason' => 'private-point-review-fixture', 'synthetic' => '1', 'capabilities' => ['laptop:', 'standalone_part:hdd', 'standalone_part:ssd', 'standalone_part:ict_cooling_fan', 'peripheral:keyboard', 'peripheral:router'], 'idempotency_key' => Str::uuid()->toString()], $extra);
    }

    private function request(array $extra = [], string $mode = 'pickup'): string
    {
        $item = $this->device($this->owner, 'standalone_part', 'ssd', $extra);

        return app(CollectionRequests::class)->submit($this->owner, $this->requestFields([$item->subject_id], $mode));
    }

    private function decision(string $id, string $decision = 'ready'): array
    {
        return ['decision' => $decision, 'reason' => 'private-review-reason-fixture', 'expected_version' => DB::table('requests')->where('id', $id)->value('version'), 'idempotency_key' => Str::uuid()->toString()];
    }

    private function rejects(callable $operation, string $field): void
    {
        try {
            $operation();
            $this->fail('Expected validation rejection.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    private function directRejection(callable $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('Expected database protection.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_administrator_pages_and_every_mutation_evidence_route_deny_other_roles_and_stale_sessions(): void
    {
        $org = app(ManageDirectory::class)->save($this->admin, 'organization', null, $this->orgFields(), UploadedFile::fake()->image('evidence.png'));
        $attachment = DB::table('evidence_links')->where('record_id', $org)->value('attachment_id');
        $id = $this->request();
        foreach ([Role::Consumer, Role::Collector, Role::Processor] as $role) {
            $user = User::factory()->create();
            $user->membership()->update(['role' => $role]);
            $this->signIn($user->refresh());
            foreach (['/admin/directory', '/admin/directory/organization/create', '/admin/directory/place/create', '/admin/directory/organization/'.$org, '/admin/reviews', '/admin/reviews/'.$id, '/admin/evidence/'.$attachment] as $url) {
                $this->get($url)->assertForbidden();
            }
            $this->post('/admin/directory/organization', $this->orgFields())->assertForbidden();
            $this->post('/admin/directory/organization/'.$org, [])->assertForbidden();
            $this->post('/admin/reviews/'.$id, $this->decision($id))->assertForbidden();
            try {
                app(ReviewRequest::class)->decide($user, $id, $this->decision($id));
                $this->fail('Action must reauthorize.');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
        $this->signIn($this->admin);
        $this->get('/admin/directory')->assertOk()->assertSee('Synthetic study record');
        $this->get('/admin/reviews/'.$id)->assertOk()->assertSee('Current blocking / review reasons');
        $this->admin->increment('session_version');
        $this->get('/admin/reviews')->assertRedirect('/login');
    }

    public function test_private_directory_contacts_evidence_and_immutable_revisions_with_retry_and_synthetic_protection(): void
    {
        $fields = $this->orgFields();
        $file = UploadedFile::fake()->image('evidence.png');
        $org = app(ManageDirectory::class)->save($this->admin, 'organization', null, $fields, $file);
        $this->assertSame($org, app(ManageDirectory::class)->save($this->admin, 'organization', null, $fields, $file));
        $point = app(ManageDirectory::class)->save($this->admin, 'place', null, $this->pointFields($org), UploadedFile::fake()->image('point.png'));
        $this->assertSame(1, DB::table('directory_revisions')->where('record_id', $org)->count());
        $row = DB::table('organizations')->where('id', $org)->first();
        $this->assertSame('private-contact-fixture', Crypt::decryptString($row->encrypted_contact));
        $this->assertStringNotContainsString('private-staff-note-fixture', $row->review_reason);
        $attachment = DB::table('evidence_links')->where('record_id', $org)->value('attachment_id');
        $this->get('/admin/evidence/'.$attachment)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Content-Disposition', 'attachment; filename="review-evidence.png"');
        $this->get('/admin/directory/place/'.$point)->assertOk()->assertSee('private-point-contact-fixture');
        $public = $this->get('/collection-points')->assertOk();
        foreach (['private-contact-fixture', 'private-point-contact-fixture', 'private-staff-note-fixture', $attachment] as $secret) {
            $public->assertDontSee($secret);
        }
        $this->directRejection(fn () => DB::table('organizations')->where('id', $org)->update(['synthetic' => false]));
        $this->directRejection(fn () => DB::table('directory_revisions')->where('record_id', $org)->delete());
        $this->directRejection(fn () => DB::table('evidence_links')->where('attachment_id', $attachment)->delete());
    }

    public function test_directory_validation_explicit_subtypes_private_error_input_and_missing_evidence(): void
    {
        $this->rejects(fn () => app(ManageDirectory::class)->save($this->admin, 'organization', null, $this->orgFields()), 'evidence');
        $org = app(ManageDirectory::class)->save($this->admin, 'organization', null, $this->orgFields(['review_status' => 'pending']));
        $this->rejects(fn () => app(ManageDirectory::class)->save($this->admin, 'place', null, $this->pointFields($org), UploadedFile::fake()->image('x.png')), 'active');
        $org2 = app(ManageDirectory::class)->save($this->admin, 'organization', null, $this->orgFields(), UploadedFile::fake()->image('x.png'));
        foreach (['standalone_part:', 'peripheral:', 'battery:', 'laptop:hdd'] as $bad) {
            $this->rejects(fn () => app(ManageDirectory::class)->save($this->admin, 'place', null, $this->pointFields($org2, ['capabilities' => [$bad]])), 'capabilities.0');
        }
        $fields = $this->pointFields($org2, ['latitude' => '91']);
        $this->post('/admin/directory/place', $fields)->assertRedirect('/admin/directory/place/create')->assertSessionHasErrors('latitude');
        $this->assertSame('Synthetic intake', session()->getOldInput('name'));
        foreach (['contact', 'latitude', 'longitude', 'reason', 'hours', 'evidence'] as $private) {
            $this->assertNull(session()->getOldInput($private));
        }
        $this->get('/admin/directory/place/create')->assertOk()->assertSee('aria-invalid="true"', false)->assertSee('latitude-error');
        foreach ([null, [], 'administrator'] as $value) {
            $this->rejects(fn () => app(ManageDirectory::class)->save($this->admin, 'organization', null, $this->orgFields() + ['reviewed_by' => $value]), 'submission');
        }
        $this->rejects(fn () => app(ManageDirectory::class)->save($this->admin, 'organization', null, $this->orgFields(), UploadedFile::fake()->create('evil.html', 1, 'text/html')), 'evidence');
        $this->rejects(fn () => app(ManageDirectory::class)->save($this->admin, 'organization', null, $this->orgFields(), UploadedFile::fake()->image('big.png')->size(5121)), 'evidence');
        $this->assertCount(10, DirectoryInput::capabilities());
    }

    public function test_ready_and_hold_reviews_are_private_versioned_atomic_idempotent_and_preserve_physical_state(): void
    {
        $id = $this->request();
        $before = DB::table('custody_current')->orderBy('subject_id')->get()->toJson();
        $fields = $this->decision($id);
        $file = UploadedFile::fake()->image('review.png');
        app(ReviewRequest::class)->decide($this->admin, $id, $fields, $file);
        $this->assertSame($id, app(ReviewRequest::class)->decide($this->admin, $id, $fields, $file));
        // A retry must include the original evidence bytes in its canonical payload.
        $review = DB::table('request_reviews')->where('request_id', $id)->first();
        $this->assertSame(1, (int) $review->evaluated_version);
        $this->assertSame('private-review-reason-fixture', Crypt::decryptString($review->reason_ciphertext));
        $this->assertDatabaseHas('requests', ['id' => $id, 'state' => 'SUBMITTED', 'review_state' => 'ready', 'version' => 2]);
        $this->assertSame('ready', app(Review::class)->state(DB::table('requests')->where('id', $id)->first()));
        $this->assertSame($before, DB::table('custody_current')->orderBy('subject_id')->get()->toJson());
        $this->assertDatabaseHas('request_items', ['request_id' => $id, 'active' => true, 'outcome' => 'outstanding']);
        $this->get('/admin/reviews/'.$id)->assertOk()->assertSee('private-review-reason-fixture');
        $this->signIn($this->owner);
        $this->get('/requests/'.$id)->assertOk()->assertSee('Reviewed for later allocation')->assertDontSee('private-review-reason-fixture')->assertDontSee('Download private');
        $this->signIn($this->admin);
        $hold = $this->decision($id, 'hold');
        app(ReviewRequest::class)->decide($this->admin, $id, $hold);
        $this->assertSame($id, app(ReviewRequest::class)->decide($this->admin, $id, $hold));
        $this->assertSame(2, DB::table('request_reviews')->where('request_id', $id)->count());
        $this->directRejection(fn () => DB::table('request_reviews')->where('id', $review->id)->update(['decision' => 'hold']));
        $this->directRejection(fn () => DB::table('requests')->where('id', $id)->update(['review_state' => 'ready']));
    }

    public function test_unresolved_safety_unknown_and_new_fan_restrictions_cannot_be_overridden(): void
    {
        foreach (['YES', 'UNKNOWN'] as $safety) {
            $id = $this->request(['safety_concern' => $safety]);
            $this->rejects(fn () => app(ReviewRequest::class)->decide($this->admin, $id, $this->decision($id)), 'decision');
            app(ReviewRequest::class)->decide($this->admin, $id, $this->decision($id, 'hold'));
            $this->assertDatabaseHas('request_items', ['request_id' => $id, 'active' => true]);
        }
        $fan = $this->device($this->owner, 'standalone_part', 'ict_cooling_fan', ['answers' => ['fan_electronics' => 'UNKNOWN']]);
        $id = app(CollectionRequests::class)->submit($this->owner, $this->requestFields([$fan->subject_id]));
        $this->rejects(fn () => app(ReviewRequest::class)->decide($this->admin, $id, $this->decision($id)), 'decision');
        foreach ([null, [], 'resolved'] as $value) {
            $this->rejects(fn () => app(ReviewRequest::class)->decide($this->admin, $id, $this->decision($id) + ['safety_override' => $value]), 'submission');
        }
        $this->assertSame(0, DB::table('request_reviews')->where('request_id', $id)->count());
    }

    public function test_point_closure_capability_loss_and_operator_suspension_invalidate_reviews_without_releasing_items(): void
    {
        $org = app(ManageDirectory::class)->save($this->admin, 'organization', null, $this->orgFields(), UploadedFile::fake()->image('org.png'));
        $pointFields = $this->pointFields($org);
        $point = app(ManageDirectory::class)->save($this->admin, 'place', null, $pointFields, UploadedFile::fake()->image('point.png'));
        $item = $this->device($this->owner, 'standalone_part', 'ssd');
        $fields = $this->requestFields([$item->subject_id], 'drop_off');
        $fields['point_id'] = $point;
        $id = app(CollectionRequests::class)->submit($this->owner, $fields);
        $decision = $this->decision($id);
        app(ReviewRequest::class)->decide($this->admin, $id, $decision);
        unset($pointFields['organization_id'],$pointFields['synthetic']);
        $pointFields['expected_version'] = 1;
        $pointFields['idempotency_key'] = Str::uuid()->toString();
        $pointFields['capabilities'] = ['laptop:'];
        app(ManageDirectory::class)->save($this->admin, 'place', $point, $pointFields);
        $this->assertDatabaseHas('requests', ['id' => $id, 'review_state' => 'stale', 'version' => 3]);
        $this->assertContains('location_or_compatibility_changed', app(Review::class)->assess(DB::table('requests')->where('id', $id)->first())['blockers']);
        $this->rejects(fn () => app(ReviewRequest::class)->decide($this->admin, $id, $this->decision($id)), 'decision');
        $pointFields['expected_version'] = 2;
        $pointFields['active'] = '0';
        $pointFields['idempotency_key'] = Str::uuid()->toString();
        app(ManageDirectory::class)->save($this->admin, 'place', $point, $pointFields);
        $orgFields = $this->orgFields(['review_status' => 'suspended', 'expected_version' => 1]);
        unset($orgFields['synthetic']);
        app(ManageDirectory::class)->save($this->admin, 'organization', $org, $orgFields);
        $this->assertDatabaseHas('request_items', ['request_id' => $id, 'active' => true, 'outcome' => 'outstanding']);
        $this->get('/admin/reviews')->assertOk()->assertSee('Changed — fresh review required');
        $this->assertSame($id, app(ReviewRequest::class)->decide($this->admin, $id, $decision));
        $this->assertDatabaseHas('requests', ['id' => $id, 'review_state' => 'stale']);
    }

    public function test_new_observation_and_consumer_date_change_invalidate_review_and_stale_commands_conflict(): void
    {
        $item = $this->device($this->owner);
        $id = app(CollectionRequests::class)->submit($this->owner, $this->requestFields([$item->subject_id]));
        $decision = $this->decision($id);
        app(ReviewRequest::class)->decide($this->admin, $id, $decision);
        $observation = $item->observations()->first()->answers;
        unset($observation['category'],$observation['subtype'],$observation['model'],$observation['identifier']);
        $observation += ['expected_version' => 1, 'idempotency_key' => Str::uuid()->toString()];
        $observation['safety_concern'] = 'UNKNOWN';
        app(RegisterDevice::class)->revise($this->owner, $item, $observation);
        $this->assertDatabaseHas('requests', ['id' => $id, 'review_state' => 'stale', 'version' => 3]);
        $fresh = $decision;
        $fresh['idempotency_key'] = Str::uuid()->toString();
        try {
            app(ReviewRequest::class)->decide($this->admin, $id, $fresh);
            $this->fail('Stale review must conflict.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->rejects(fn () => app(ReviewRequest::class)->decide($this->admin, $id, $this->decision($id)), 'decision');
        $this->assertSame(1, DB::table('request_reviews')->where('request_id', $id)->count());
    }

    public function test_failure_after_private_file_write_rolls_back_records_history_commands_and_file(): void
    {
        $id = $this->request();
        $before = DB::table('commands')->count();
        $beforeFiles = Storage::disk('directory_evidence')->allFiles();
        DB::unprepared("CREATE FUNCTION fail_review_outbox() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'synthetic rollback'; END; $$; CREATE TRIGGER fail_review_outbox BEFORE INSERT ON notification_outbox FOR EACH ROW EXECUTE FUNCTION fail_review_outbox();");
        try {
            app(ReviewRequest::class)->decide($this->admin, $id, $this->decision($id), UploadedFile::fake()->image('review.png'));
            $this->fail('Expected injected database failure.');
        } catch (QueryException) {
            $this->assertSame(0, DB::table('request_reviews')->where('request_id', $id)->count());
            $this->assertSame($before, DB::table('commands')->count());
            $this->assertSame($beforeFiles, Storage::disk('directory_evidence')->allFiles());
            $this->assertDatabaseHas('requests', ['id' => $id, 'review_state' => 'pending', 'version' => 1]);
        }
    }

    public function test_pdf_evidence_is_private_download_only_and_active_or_disguised_payloads_are_rejected(): void
    {
        $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Count 0 /Kids [] >>\nendobj\ntrailer\n<< /Root 1 0 R /Size 3 >>\n%%EOF\n";
        $org = app(ManageDirectory::class)->save($this->admin, 'organization', null, $this->orgFields(), UploadedFile::fake()->createWithContent('review.pdf', $pdf));
        $attachment = DB::table('evidence_links')->where('record_id', $org)->value('attachment_id');
        $this->get('/admin/evidence/'.$attachment)->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('Content-Disposition', 'attachment; filename="review-evidence.pdf"')->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'");
        foreach ([$pdf.'/JavaScript (alert(1))', '<html>not evidence</html>'] as $bytes) {
            $this->rejects(fn () => app(ManageDirectory::class)->save($this->admin, 'organization', null, $this->orgFields(), UploadedFile::fake()->createWithContent('bad.pdf', $bytes)), 'evidence');
        }
        $this->assertStringNotContainsString('private-staff-note-fixture', DB::table('domain_events')->where('aggregate_id', $org)->get()->toJson());
        $this->signIn($this->owner);
        $this->get('/admin/evidence/'.$attachment)->assertForbidden();
    }

    public function test_directory_mutation_failure_rolls_back_evidence_history_invalidation_and_command(): void
    {
        $id = $this->request();
        $counts = [];
        foreach (['organizations', 'directory_revisions', 'attachments', 'evidence_links', 'commands', 'domain_events', 'notification_outbox'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }
        $files = Storage::disk('directory_evidence')->allFiles();
        DB::unprepared("CREATE FUNCTION fail_directory_outbox() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'synthetic rollback'; END; $$; CREATE TRIGGER fail_directory_outbox BEFORE INSERT ON notification_outbox FOR EACH ROW EXECUTE FUNCTION fail_directory_outbox();");
        try {
            app(ManageDirectory::class)->save($this->admin, 'organization', null, $this->orgFields(), UploadedFile::fake()->image('failed.png'));
            $this->fail('Expected injected failure.');
        } catch (QueryException) {
            foreach ($counts as $table => $count) {
                $this->assertSame($count, DB::table($table)->count(), $table);
            }
            $this->assertSame($files, Storage::disk('directory_evidence')->allFiles());
            $this->assertDatabaseHas('requests', ['id' => $id, 'version' => 1, 'review_state' => 'pending']);
        }
    }

    public function test_date_change_cancel_account_and_service_area_changes_cannot_leave_a_ready_review_current(): void
    {
        $id = $this->request([], 'drop_off');
        app(ReviewRequest::class)->decide($this->admin, $id, $this->decision($id));
        $items = DB::table('request_items')->where('request_id', $id)->pluck('item_id')->all();
        app(CollectionRequests::class)->change($this->owner, $id, ['items' => $items, 'operation' => 'date', 'reason' => 'Synthetic date correction.', 'preferred_date' => now('Africa/Nairobi')->addDays(4)->toDateString(), 'expected_version' => 2, 'idempotency_key' => Str::uuid()->toString()]);
        $this->assertDatabaseHas('requests', ['id' => $id, 'review_state' => 'stale', 'version' => 3]);
        app(ReviewRequest::class)->decide($this->admin, $id, $this->decision($id));
        DB::table('service_areas')->update(['active' => false]);
        $this->assertSame('stale', app(Review::class)->state(DB::table('requests')->where('id', $id)->first()));
        $this->rejects(fn () => app(ReviewRequest::class)->decide($this->admin, $id, $this->decision($id)), 'decision');
        $this->owner->membership()->update(['active' => false]);
        $this->assertContains('owner_unavailable', app(Review::class)->assess(DB::table('requests')->where('id', $id)->first())['blockers']);
        $this->owner->membership()->update(['active' => true]);
        app(CollectionRequests::class)->change($this->owner, $id, ['items' => $items, 'operation' => 'cancel', 'reason' => 'Synthetic cancellation.', 'expected_version' => 4, 'idempotency_key' => Str::uuid()->toString()]);
        $this->assertDatabaseHas('requests', ['id' => $id, 'state' => 'CANCELLED', 'review_state' => 'stale']);
        $this->rejects(fn () => app(ReviewRequest::class)->decide($this->admin, $id, $this->decision($id, 'hold')), 'decision');
    }

    public function test_initial_admin_bootstrap_is_local_only_refuses_existing_accounts_and_adds_only_first_membership(): void
    {
        $before = DB::table('users')->count();
        $this->artisan('app:create-initial-administrator')->assertFailed();
        $this->app->detectEnvironment(fn () => 'local');
        try {
            $this->artisan('app:create-initial-administrator')->expectsOutput('An active Administrator already exists. No account was changed.')->assertFailed();
            $this->admin->membership()->update(['active' => false]);
            $fields = ['Administrator display name' => 'Synthetic bootstrap admin', 'Administrator email' => 'synthetic-bootstrap@example.test', 'Private password' => 'SyntheticOnly123', 'Confirm private password' => 'SyntheticOnly123'];
            $command = $this->artisan('app:create-initial-administrator');
            foreach ($fields as $question => $answer) {
                $command->expectsQuestion($question, $answer);
            }
            $command->assertSuccessful()->execute();
            $user = User::where('email', 'synthetic-bootstrap@example.test')->firstOrFail();
            $this->assertSame(Role::Administrator, $user->role());
            $this->assertTrue(Hash::check('SyntheticOnly123', $user->password_hash));
            $this->assertSame($before + 1, DB::table('users')->count());
            $this->assertSame(Role::Consumer, $this->owner->fresh()->role());
            $this->artisan('app:create-initial-administrator')->assertFailed();
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }
    }
}
