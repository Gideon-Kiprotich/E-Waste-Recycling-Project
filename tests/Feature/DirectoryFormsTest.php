<?php

namespace Tests\Feature;

use App\Actions\ManageDirectory;
use App\Enums\Role;
use App\Models\User;
use App\Support\Collection\AdminMutation;
use App\Support\Collection\DirectoryDuplicates;
use App\Support\Collection\SiteLocation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\CollectionFixtures;
use Tests\TestCase;

class DirectoryFormsTest extends TestCase
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
        $this->actingAs($this->admin)->withSession(['auth_session_version' => $this->admin->session_version]);
        $this->withCookie(config('session.cookie'), session()->getId());
    }

    private function organization(string $name, array $extra = []): array
    {
        return array_replace(['name' => $name, 'kind' => 'collector', 'review_status' => 'pending', 'contact' => 'Synthetic private contact', 'reason' => 'Synthetic change', 'synthetic' => '1', 'idempotency_key' => Str::uuid()->toString()], $extra);
    }

    private function place(string $org, string $name, array $extra = [], ?string $id = null): array
    {
        $fields = array_replace(['name' => $name, 'kind' => 'collection_point', 'review_status' => 'pending', 'active' => '0', 'organization_id' => $org, 'synthetic' => '1', 'contact' => 'Synthetic contact', 'reason' => 'Synthetic site', 'latitude' => '-1.280000', 'longitude' => '36.820000', 'hours' => 'Unknown; synthetic', 'capabilities' => ['standalone_part:hdd', 'peripheral:keyboard'], 'idempotency_key' => Str::uuid()->toString()], $extra);
        $fields['site_confirmation'] = SiteLocation::token($this->admin->id, $id, $fields);
        if ($id) {
            unset($fields['synthetic'], $fields['organization_id']);
        }

        return $fields;
    }

    private function save(string $kind, array $fields, ?string $id = null, ?UploadedFile $file = null): string
    {
        return app(ManageDirectory::class)->save($this->admin, $kind, $id, $fields, $file);
    }

    private function rejects(callable $operation, string $field): void
    {
        try {
            $operation();
            $this->fail('Expected field error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    public function test_normalized_organization_names_include_inactive_kinds_edits_direct_constraints_and_retries(): void
    {
        $fields = $this->organization("  Nairobi\tCycle Ltd  ", ['review_status' => 'suspended']);
        $id = $this->save('organization', $fields);
        $this->assertSame($id, $this->save('organization', $fields));
        $before = DB::table('commands')->count();
        $this->rejects(fn () => $this->save('organization', $this->organization('nairobi cycle LTD', ['kind' => 'processor'])), 'name');
        $second = $this->save('organization', $this->organization('Distinct Thika Fixture'));
        $edit = $this->organization('NAIROBI CYCLE LTD', ['expected_version' => 1]);
        unset($edit['synthetic']);
        $this->rejects(fn () => $this->save('organization', $edit, $second), 'name');
        $copy = (array) DB::table('organizations')->where('id', $id)->first();
        $copy['id'] = Str::uuid()->toString();
        $copy['code'] = 'synthetic-direct-copy';
        $copy['name'] = 'NAIROBI   CYCLE LTD';
        try {
            DB::transaction(fn () => DB::table('organizations')->insert($copy));
            $this->fail('Database must enforce the normalized key.');
        } catch (QueryException $e) {
            $this->assertSame('23505', $e->getCode());
            $this->assertStringContainsString('organizations_name_identity', $e->getMessage());
        }
        $this->assertSame($before + 1, DB::table('commands')->count());
        $this->assertSame('café ict', DirectoryDuplicates::nameKey(" CAFE\u{0301}  ICT "));
        $this->assertNotSame(DirectoryDuplicates::nameKey('Nairobi Ltd'), DirectoryDuplicates::nameKey('Nairobi Limited'));
    }

    public function test_variants_require_review_and_offer_authorized_existing_record_guidance_without_automatic_merging(): void
    {
        $id = $this->save('organization', $this->organization('Kilimani Cycle Ltd'));
        $fields = $this->organization('Kilimani Cycle Limited');
        $this->post('/admin/directory/organization', $fields)->assertSessionHasErrors('duplicate_review_ack');
        $this->get('/admin/directory/organization/create')->assertOk()->assertSee('/admin/directory/organization/'.$id, false)->assertSee('Similar name');
        $fields['duplicate_review_ack'] = '1';
        $distinct = $this->save('organization', $fields);
        $this->assertNotSame($id, $distinct);
        $this->get('/admin/directory/organization/'.$distinct)->assertSee('Existing records to review');
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['auth_session_version' => $user->session_version])->get('/admin/directory/organization/'.$distinct)->assertForbidden();
    }

    public function test_optional_verified_issuer_identifier_normalization_requires_evidence_and_is_database_unique(): void
    {
        $fields = $this->organization('Synthetic registry fixture', ['brs_number' => ' SYN / 123-A ', 'identifier_verified' => '1']);
        $this->rejects(fn () => $this->save('organization', $fields), 'brs_number');
        $file = UploadedFile::fake()->image('synthetic-registry.png');
        $id = $this->save('organization', $fields, file: $file);
        $this->assertSame($id, $this->save('organization', $fields, file: $file));
        $this->assertDatabaseHas('organizations', ['id' => $id, 'brs_number' => 'SYN/123-A', 'identifier_verified_by' => $this->admin->id]);
        $this->rejects(fn () => $this->save('organization', $this->organization('Distinct entity', ['brs_number' => 'syn/123-a', 'identifier_verified' => '1']), file: $file), 'brs_number');
        $edit = $this->organization('Synthetic registry fixture', ['expected_version' => 1, 'brs_number' => null]);
        unset($edit['synthetic']);
        $this->rejects(fn () => $this->save('organization', $edit, $id), 'brs_number');
        foreach (['No number needed A', 'No number needed B'] as $name) {
            $this->save('organization', $this->organization($name, ['duplicate_review_ack' => '1']));
        }
        $this->assertSame(1, DB::table('organizations')->whereNotNull('brs_number')->count());
        // A synthetic scalar edge case must not be mistaken for a missing identifier.
        $zero = $this->organization('Synthetic scalar fixture', ['brs_number' => '0', 'identifier_verified' => '1']);
        $zeroId = $this->save('organization', $zero, file: $file);
        $this->assertDatabaseHas('organizations', ['id' => $zeroId, 'brs_number' => '0', 'identifier_verified_by' => $this->admin->id]);
        $this->rejects(fn () => $this->save('organization', $this->organization('Separate scalar fixture', ['brs_number' => '0', 'identifier_verified' => '1']), file: $file), 'brs_number');
        $copy = (array) DB::table('organizations')->where('id', $id)->first();
        $copy['id'] = Str::uuid()->toString();
        $copy['code'] = 'synthetic-registry-copy';
        $copy['name'] = 'Different registry copy';
        try {
            DB::transaction(fn () => DB::table('organizations')->insert($copy));
            $this->fail('Registry key must be globally unique.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('organizations_brs_identity', $e->getMessage());
        }
    }

    public function test_place_type_changes_cannot_bypass_identity_but_legitimate_colocation_is_reviewable(): void
    {
        $org = $this->save('organization', $this->organization('Synthetic site operator'));
        $fields = $this->place($org, 'Kasarani entrance fixture', ['review_status' => 'suspended']);
        $id = $this->save('place', $fields);
        $this->assertSame($id, $this->save('place', $fields));
        $this->rejects(fn () => $this->save('place', $this->place($org, ' KASARANI entrance fixture ', ['kind' => 'facility'])), 'name');
        $colocated = $this->place($org, 'Separate storage wing fixture', ['kind' => 'depot']);
        $this->rejects(fn () => $this->save('place', $colocated), 'duplicate_review_ack');
        $colocated['duplicate_review_ack'] = '1';
        $second = $this->save('place', $colocated);
        $edit = $this->place($org, 'KASARANI entrance fixture', ['kind' => 'depot', 'expected_version' => 1], $second);
        $this->rejects(fn () => $this->save('place', $edit, $second), 'name');
        $otherOrg = $this->save('organization', $this->organization('Other independent operator'));
        $this->save('place', $this->place($otherOrg, 'Kasarani entrance fixture'));
        $copy = (array) DB::table('places')->where('id', $id)->first();
        $copy['id'] = Str::uuid()->toString();
        $copy['code'] = 'synthetic-site-copy';
        $copy['kind'] = 'depot';
        try {
            DB::transaction(fn () => DB::table('places')->insert($copy));
            $this->fail('Type cannot bypass the unique index.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('places_site_identity', $e->getMessage());
        }
        $this->assertDatabaseCount('places', 6);
    }

    public function test_nearby_or_matching_private_address_is_only_a_warning_and_no_plaintext_is_flashed(): void
    {
        $org = $this->save('organization', $this->organization('Address fixture operator'));
        $this->save('place', $this->place($org, 'First entrance', ['site_address' => 'Synthetic PRIVATE entrance landmark']));
        $near = $this->place($org, 'Independent room', ['latitude' => '-1.280500']);
        $this->rejects(fn () => $this->save('place', $near), 'duplicate_review_ack');
        $far = $this->place($org, 'Far room', ['latitude' => '-1.290000', 'site_address' => ' synthetic private ENTRANCE landmark ']);
        $this->rejects(fn () => $this->save('place', $far), 'duplicate_review_ack');
        $far['duplicate_review_ack'] = '1';
        $id = $this->save('place', $far);
        $this->assertStringNotContainsString('PRIVATE', DB::table('places')->where('id', $id)->value('site_address_ciphertext'));
        $this->get('/collection-points')->assertDontSee('PRIVATE');
    }

    public function test_site_confirmation_binds_actor_record_version_command_and_coordinates_and_survives_unrelated_errors_privately(): void
    {
        $org = $this->save('organization', $this->organization('Map fixture operator'));
        $fields = $this->place($org, 'Synthetic map entrance');
        unset($fields['site_confirmation']);
        $this->post('/admin/directory/place', $fields + ['intent' => 'confirm_site'])->assertRedirect('/admin/directory/place/create');
        $state = SiteLocation::recalled(null);
        $this->assertNotEmpty($state['site_confirmation']);
        $html = $this->get('/admin/directory/place/create')->assertOk()->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $this->assertSame('1', $xpath->evaluate('string(//select[@name="synthetic"]/option[@selected]/@value)'));
        $this->assertSame('0', $xpath->evaluate('string(//select[@name="active"]/option[@selected]/@value)'));
        // Re-loading the form retains the command key bound to the private selection.
        $this->assertStringContainsString($fields['idempotency_key'], $this->get('/admin/directory/place/create')->getContent());
        $this->assertStringNotContainsString('-1.280000', session('directory_location'));
        $fields['site_confirmation'] = $state['site_confirmation'];
        $fields['contact'] = '';
        $this->post('/admin/directory/place', $fields)->assertSessionHasErrors('contact');
        $this->assertNull(session()->getOldInput('latitude'));
        $this->assertSame($state['site_confirmation'], SiteLocation::recalled(null)['site_confirmation']);
        $this->get('/admin/directory/place/create')->assertOk()->assertSee('value="-1.280000"', false)->assertSee('data-confirmed="true"', false);
        $fields['contact'] = 'Synthetic contact';
        $changed = $fields;
        $changed['latitude'] = '-1.281000';
        $this->rejects(fn () => $this->save('place', $changed), 'site_confirmation');
        $id = $this->save('place', $fields);
        $edit = $this->place($org, 'Synthetic map entrance', ['expected_version' => 1], $id);
        $this->save('place', $edit, $id);
        $this->assertSame($id, $this->save('place', $fields));
        $stale = $edit;
        $stale['idempotency_key'] = Str::uuid()->toString();
        $stale['site_confirmation'] = SiteLocation::token($this->admin->id, $id, $stale);
        $this->post('/admin/directory/place/'.$id, $stale)->assertStatus(409);
        foreach ([null, [], '91', 'bad'] as $bad) {
            $invalid = $fields;
            $invalid['latitude'] = $bad;
            $this->post('/admin/directory/place', $invalid + ['intent' => 'confirm_site'])->assertSessionHasErrors('latitude');
        }
        $this->assertDatabaseCount('directory_revisions', 3);
    }

    public function test_historical_successful_place_retry_does_not_require_new_confirmation_or_duplicate_acknowledgment(): void
    {
        $place = DB::table('places')->where('code', 'synthetic-study-all-ict')->first();
        $fields = $this->place($place->organization_id, $place->name);
        unset($fields['site_confirmation']);
        sort($fields['capabilities']);
        $command = AdminMutation::command($this->admin, 'manage_place:new', $fields['idempotency_key'], AdminMutation::hash($fields + ['evidence_checksum' => null]), $place->id);
        $before = DB::table('directory_revisions')->count();
        $this->assertSame($place->id, $this->save('place', $fields));
        $this->assertSame($before, DB::table('directory_revisions')->count());
        $this->assertDatabaseHas('commands', ['id' => $command, 'status' => 'completed']);
    }

    public function test_capabilities_have_semantic_groups_exact_choices_and_accessible_map_fallback(): void
    {
        $response = $this->get('/admin/directory/place/create')->assertOk();
        foreach (['Whole devices', 'Peripherals', 'Standalone parts', 'capability-grid', 'Manual latitude / longitude entry', 'Confirm site entrance', 'current device', 'facility entrance'] as $text) {
            $response->assertSee($text);
        }
        $this->assertSame(10, substr_count($response->getContent(), 'name="capabilities[]"'));
        $this->assertSame(3, substr_count($response->getContent(), 'class="capability-group"'));
    }

    public function test_demo_copy_preview_and_repeat_apply_preserve_operational_fields_user_edits_and_references(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        try {
            $row = DB::table('places')->where('code', 'synthetic-study-inactive')->first();
            DB::table('places')->where('id', $row->id)->update(['name' => 'Synthetic demonstration: inactive']);
            $before = DB::table('places')->where('id', $row->id)->first();
            $this->artisan('app:refresh-nairobi-demo-copy')->assertSuccessful()->execute();
            $this->assertSame($before->name, DB::table('places')->where('id', $row->id)->value('name'));
            $this->artisan('app:refresh-nairobi-demo-copy', ['--apply' => true])->assertSuccessful()->execute();
            $after = DB::table('places')->where('id', $row->id)->first();
            $this->assertSame('Nairobi synthetic study point: inactive', $after->name);
            $after->name = $before->name;
            $this->assertEquals($before, $after);
            DB::table('places')->where('id', $row->id)->update(['name' => 'User edited synthetic name']);
            $this->artisan('app:refresh-nairobi-demo-copy', ['--apply' => true])->assertSuccessful()->execute();
            $this->assertSame('User edited synthetic name', DB::table('places')->where('id', $row->id)->value('name'));
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }
    }
}
