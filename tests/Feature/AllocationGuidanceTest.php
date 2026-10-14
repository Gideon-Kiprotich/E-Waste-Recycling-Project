<?php

namespace Tests\Feature;

use App\Actions\AllocateCollector;
use App\Actions\CollectionRequests;
use App\Actions\ManageCollector;
use App\Actions\ManageDirectory;
use App\Actions\ReviewRequest;
use App\Enums\Role;
use App\Models\User;
use App\Support\Assignments\AllocationGuide;
use App\Support\Collection\SiteLocation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\CollectionFixtures;
use Tests\TestCase;

class AllocationGuidanceTest extends TestCase
{
    use CollectionFixtures, DatabaseTransactions;

    private User $admin;

    private User $owner;

    private string $request;

    private string $operator;

    private string $place;

    private User $collector;

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
        $device = $this->device($this->owner, 'standalone_part', 'ssd');
        $this->request = app(CollectionRequests::class)->submit($this->owner, $this->requestFields([$device->subject_id]));
        $this->signIn($this->admin);
    }

    private function signIn(User $user): void
    {
        $this->actingAs($user)->withSession(['auth_session_version' => $user->session_version]);
        session()->save();
        $this->withCookie(config('session.cookie'), session()->getId());
    }

    private function orgFields(array $extra = []): array
    {
        return array_replace(['name' => 'Synthetic guidance operator', 'kind' => 'both', 'synthetic' => '1', 'review_status' => 'approved', 'contact' => 'Private synthetic operator contact', 'reason' => 'Private synthetic operator reason', 'idempotency_key' => Str::uuid()->toString(), 'duplicate_review_ack' => '1'], $extra);
    }

    private function setupControls(array $capabilities = ['standalone_part:ssd']): void
    {
        $this->post('/admin/directory/organization', $this->orgFields() + ['evidence' => UploadedFile::fake()->image('operator.png')])->assertRedirect();
        $this->operator = DB::table('organizations')->where('name', 'Synthetic guidance operator')->value('id');
        $fields = ['name' => 'Synthetic guidance receiving facility', 'kind' => 'facility', 'organization_id' => $this->operator, 'synthetic' => '1', 'review_status' => 'approved', 'active' => '1', 'contact' => 'Private synthetic place contact', 'reason' => 'Private synthetic place reason', 'hours' => 'Synthetic hours only', 'latitude' => '-1.282000', 'longitude' => '36.822000', 'capabilities' => $capabilities, 'duplicate_review_ack' => '1', 'idempotency_key' => Str::uuid()->toString()];
        $this->post('/admin/directory/place', $fields + ['intent' => 'confirm_site'])->assertRedirect('/admin/directory/place/create');
        $fields['site_confirmation'] = SiteLocation::recalled(null)['site_confirmation'];
        $this->post('/admin/directory/place', $fields + ['evidence' => UploadedFile::fake()->image('place.png')])->assertRedirect();
        $this->place = DB::table('places')->where('name', 'Synthetic guidance receiving facility')->value('id');
        $this->post('/admin/collectors', ['operation' => 'create', 'display_name' => 'Synthetic guidance Collector', 'email' => 'guidance-collector@example.test', 'password' => 'SyntheticPrivate123', 'password_confirmation' => 'SyntheticPrivate123', 'organization_ref' => $this->operator.':1', 'reason' => 'Private synthetic access reason', 'idempotency_key' => Str::uuid()->toString()])->assertRedirect();
        $this->collector = User::where('email', 'guidance-collector@example.test')->firstOrFail();
    }

    private function decisionFields(): array
    {
        return ['decision' => 'ready', 'reason' => 'Private synthetic Ready review', 'expected_version' => DB::table('requests')->where('id', $this->request)->value('version'), 'idempotency_key' => Str::uuid()->toString()];
    }

    private function offerFields(): array
    {
        $r = DB::table('requests')->where('id', $this->request)->first();

        return ['operation' => 'offer', 'membership_ref' => $this->collector->membership->id.':'.$this->collector->membership->version, 'facility_ref' => $this->place.':'.DB::table('places')->where('id', $this->place)->value('version'), 'expected_request_version' => $r->version, 'expected_review_id' => $r->review_id, 'reason' => 'Private synthetic offer reason', 'idempotency_key' => Str::uuid()->toString()];
    }

    public function test_supported_setup_review_offer_acceptance_controls_and_private_owner_milestone(): void
    {
        $this->get('/admin/assignments/'.$this->request)->assertOk()->assertSee('No eligible Collector choices')->assertSee('No approved receiving operator exists')->assertSee('Open request review');
        $this->post('/admin/reviews/'.$this->request, $this->decisionFields())->assertSessionHasErrors('decision');
        $this->assertStringContainsString('No compatible receiving place', session('errors')->first('decision'));
        $this->assertStringNotContainsString('safety restrictions', session('errors')->first('decision'));
        $this->setupControls();
        $this->get('/admin/assignments/'.$this->request)->assertSee('Fresh review required')->assertSee('Synthetic guidance receiving facility')->assertSee('SSD')->assertDontSee('Private synthetic place contact');
        $this->post('/admin/reviews/'.$this->request, $this->decisionFields())->assertRedirect('/admin/reviews/'.$this->request);
        $version = DB::table('requests')->where('id', $this->request)->value('version');
        $page = $this->get('/admin/assignments/'.$this->request)->assertOk()->assertSee('Collector and operator')->assertSee('accepts all required items');
        $this->post('/admin/assignments/'.$this->request, $this->offerFields())->assertRedirect('/admin/assignments/'.$this->request);
        $id = DB::table('assignments')->where('request_id', $this->request)->value('id');
        $this->get('/admin/assignments/'.$this->request)->assertSee('Pending Collector acknowledgment');
        $this->signIn($this->collector);
        $this->get('/collector/assignments/'.$id)->assertOk()->assertDontSee('Synthetic contact only')->assertDontSee('Private synthetic offer reason');
        $this->post('/collector/assignments/'.$id, ['operation' => 'accept', 'expected_version' => 1, 'idempotency_key' => Str::uuid()->toString()])->assertRedirect('/collector/assignments/'.$id);
        $this->get('/collector/assignments/'.$id)->assertSee('Accepted — not scheduled')->assertSee('Synthetic contact only');
        $this->signIn($this->owner);
        $this->get('/requests/'.$this->request)->assertSee('Accepted — not scheduled')->assertDontSee('Private synthetic offer reason');
        $this->assertDatabaseHas('requests', ['id' => $this->request, 'version' => $version, 'review_state' => 'ready']);
        $this->assertSame(1, DB::table('request_items')->where('request_id', $this->request)->where('active', true)->count());
        $this->assertSame([1], DB::table('custody_current')->pluck('version')->all());
    }

    public function test_incompatible_options_are_explained_and_filtered_without_weakening_server_validation(): void
    {
        $this->setupControls(['laptop:']);
        $guide = app(AllocationGuide::class)->inspect(DB::table('requests')->where('id', $this->request)->first());
        $this->assertSame([], $guide['placeOptions']);
        // The demo operator supports SSDs, but this newly scoped operator only accepts laptops.
        $this->assertSame([], $guide['memberOptions']);
        $this->get('/admin/assignments/'.$this->request)->assertSee('none accepts every required category')->assertSee('Missing acceptance for one or more required')->assertSee('Synthetic guidance Collector')->assertSee('Linked operator has no active, reviewed place');
        $this->post('/admin/assignments/'.$this->request, $this->offerFields())->assertSessionHasErrors();
        $this->assertDatabaseCount('assignments', 0);
    }

    public function test_unscoped_inactive_and_wrong_kind_collectors_get_actionable_guidance(): void
    {
        $collector = User::factory()->create(['display_name' => 'Synthetic unscoped Collector']);
        $collector->membership()->update(['role' => Role::Collector]);
        $this->get('/admin/assignments/'.$this->request)->assertSee('No operator link')->assertSee('/admin/collectors/'.$collector->id);
        $collector->membership()->update(['active' => false]);
        $this->get('/admin/assignments/'.$this->request)->assertSee('Account or membership inactive');
        $org = app(ManageDirectory::class)->save($this->admin, 'organization', null, $this->orgFields(['kind' => 'processor']), UploadedFile::fake()->image('processor.png'));
        $collector->membership()->update(['active' => true, 'organization_id' => $org]);
        $this->get('/admin/assignments/'.$this->request)->assertSee('not an approved Collector operator or Collector and processor');
    }

    public function test_directory_change_after_ready_requires_review_again_and_stale_recovery_stays_in_admin_scope(): void
    {
        $this->setupControls();
        app(ReviewRequest::class)->decide($this->admin, $this->request, $this->decisionFields());
        $offer = $this->offerFields();
        $fields = $this->orgFields(['expected_version' => 1]);
        unset($fields['synthetic']);
        $this->post('/admin/directory/organization/'.$this->operator, $fields)->assertRedirect();
        $this->get('/admin/assignments/'.$this->request)->assertSee('Fresh review required')->assertSee('record Ready again')->assertDontSee('id="membership_ref"', false);
        $this->post('/admin/assignments/'.$this->request, $offer)->assertStatus(409)->assertSee('Reload pickup allocation')->assertSee('/admin/assignments/'.$this->request)->assertDontSee('Back to My devices');
        $this->get('/admin/assignments/'.$this->request)->assertOk();
        $this->post('/admin/reviews/'.$this->request, $this->decisionFields())->assertRedirect();
        $this->post('/admin/assignments/'.$this->request, $this->offerFields())->assertRedirect();
        $this->assertDatabaseCount('assignments', 1);
        $this->assertDatabaseHas('request_items', ['request_id' => $this->request, 'active' => true]);
    }

    public function test_unchanged_link_preserves_assignment_sessions_and_versions_but_records_a_retry_safe_check(): void
    {
        $this->setupControls();
        app(ReviewRequest::class)->decide($this->admin, $this->request, $this->decisionFields());
        $id = app(AllocateCollector::class)->allocate($this->admin, $this->request, $this->offerFields());
        app(AllocateCollector::class)->respond($this->collector, $id, ['operation' => 'accept', 'expected_version' => 1, 'idempotency_key' => Str::uuid()->toString()]);
        $before = [DB::table('users')->where('id', $this->collector->id)->first(), DB::table('memberships')->where('user_id', $this->collector->id)->first(), DB::table('assignments')->where('id', $id)->first()];
        $this->get('/admin/collectors/'.$this->collector->id)->assertSee('Current operator: Synthetic guidance operator')->assertSee('value="'.$this->operator.':1" selected', false);
        $fields = ['operation' => 'link', 'organization_ref' => $this->operator.':1', 'expected_version' => 1, 'reason' => 'Private unchanged access reason', 'idempotency_key' => Str::uuid()->toString()];
        app(ManageCollector::class)->save($this->admin, $this->collector->id, $fields);
        app(ManageCollector::class)->save($this->admin, $this->collector->id, $fields);
        $this->assertEquals($before, [DB::table('users')->where('id', $this->collector->id)->first(), DB::table('memberships')->where('user_id', $this->collector->id)->first(), DB::table('assignments')->where('id', $id)->first()]);
        $this->assertSame(1, DB::table('domain_events')->where('type', 'collector_access_unchanged')->count());
        $this->signIn($this->collector);
        $this->get('/collector/assignments/'.$id)->assertOk()->assertSee('Synthetic contact only');
        $this->post('/admin/collectors/'.$this->collector->id, $fields)->assertForbidden();
        $this->signIn($this->admin);
        $fields['reason'] = 'Different unchanged reason';
        $this->post('/admin/collectors/'.$this->collector->id, $fields)->assertStatus(409)->assertSee('Reload Collector access');
        $fields['idempotency_key'] = Str::uuid()->toString();
        $fields['expected_version'] = 99;
        $this->post('/admin/collectors/'.$this->collector->id, $fields)->assertStatus(409);
    }

    public function test_safety_and_elapsed_dates_are_distinct_from_setup_and_cannot_be_overridden(): void
    {
        $this->setupControls();
        $item = $this->device($this->owner, 'phone', null, ['safety_concern' => 'UNKNOWN']);
        $id = app(CollectionRequests::class)->submit($this->owner, $this->requestFields([$item->subject_id]));
        $fields = ['decision' => 'ready', 'reason' => 'Synthetic restriction', 'expected_version' => 1, 'idempotency_key' => Str::uuid()->toString()];
        $this->post('/admin/reviews/'.$id, $fields)->assertSessionHasErrors('decision');
        $this->assertStringContainsString('Safety is unknown', session('errors')->first('decision'));
        $this->get('/admin/assignments/'.$id)->assertSee('Safety / scope restriction')->assertSee('No safety override');
        $this->travel(2)->days();
        try {
            $this->post('/admin/reviews/'.$this->request, $this->decisionFields())->assertSessionHasErrors('decision');
            $this->assertStringContainsString('preferred date has passed', session('errors')->first('decision'));
            $this->get('/admin/assignments/'.$this->request)->assertSee('Ask the Consumer')->assertSee('My requests');
        } finally {
            $this->travelBack();
        }
        $this->assertDatabaseCount('assignments', 0);
    }

    public function test_guidance_is_admin_only_and_keeps_private_directory_and_request_data_out_of_allocation(): void
    {
        $this->setupControls();
        $this->get('/admin/assignments/'.$this->request)->assertDontSee('Private synthetic operator contact')->assertDontSee('Private synthetic place reason')->assertDontSee('Synthetic contact only');
        foreach (Role::cases() as $role) {
            if ($role === Role::Administrator) {
                continue;
            }
            $user = User::factory()->create();
            $user->membership()->update(['role' => $role]);
            $user->refresh();
            $this->signIn($user);
            $this->get('/admin/assignments/'.$this->request)->assertForbidden();
        }
    }
}
