<?php

namespace Tests\Feature;

use App\Actions\RegisterDevice;
use App\Enums\Role;
use App\Models\Item;
use App\Models\User;
use App\Support\Devices\Identifiers;
use App\Support\Devices\Questions;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;
use Zxing\QrReader;

class PartsIntakeTest extends TestCase
{
    use DatabaseTransactions;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1]);
        Storage::fake('device_photos');
        $this->owner = User::factory()->create();
        $this->signIn($this->owner);
    }

    private function signIn(User $user): void
    {
        $this->actingAs($user)->withSession(['auth_session_version' => $user->session_version]);
    }

    private function fields(string $subtype = 'hdd'): array
    {
        $fields = ['category' => 'standalone_part', 'subtype' => $subtype, 'idempotency_key' => Str::uuid()->toString(), 'physical_damage' => 'UNKNOWN', 'safety_concern' => 'UNKNOWN', 'reason' => 'Synthetic previously observed condition.', 'answers' => array_fill_keys(array_keys(Questions::tests('standalone_part', $subtype)), 'UNKNOWN')];
        if ($subtype === 'ict_cooling_fan') {
            $fields['answers']['fan_scope'] = 'YES';
            $fields['answers']['fan_electronics'] = 'BASIC';
        }

        return $fields;
    }

    private function register(string $subtype = 'hdd', array $extra = []): Item
    {
        $fields = array_replace_recursive($this->fields($subtype), $extra);
        $response = $this->post('/items', $fields)->assertRedirect()->assertSessionHasNoErrors();
        $item = Item::with('subject', 'category', 'storageAsset')->findOrFail(basename($response->headers->get('Location')));
        $this->evaluateFacets();

        return $item;
    }

    private function revision(Item $item): array
    {
        $fields = $this->fields($item->subtype);
        unset($fields['category'], $fields['subtype']);
        $fields['expected_version'] = $item->subject->version;

        return $fields;
    }

    private function evaluateFacets(): void
    {
        // An outer rollback never evaluates deferred triggers; explicitly reach their enforcement boundary.
        DB::statement('SET CONSTRAINTS complete_item_storage, complete_storage_item IMMEDIATE');
        DB::statement('SET CONSTRAINTS complete_item_storage, complete_storage_item DEFERRED');
    }

    private function assertCounts(int $count, int $facets, int $photos = 0): void
    {
        foreach (['subjects', 'items', 'item_observations', 'commands', 'domain_events', 'custody_current', 'notification_outbox'] as $table) {
            $this->assertDatabaseCount($table, $count);
        }
        $this->assertDatabaseCount('storage_assets', $facets);
        $this->assertDatabaseCount('attachments', $photos);
        $this->assertDatabaseCount('evidence_links', $photos);
        $this->assertCount($photos, Storage::disk('device_photos')->allFiles());
    }

    public function test_exact_parts_create_one_identity_with_correct_version_data_and_facet(): void
    {
        foreach (['hdd', 'ssd', 'ict_cooling_fan'] as $subtype) {
            $item = $this->register($subtype);
            $this->assertSame('standalone_part', $item->intake_class);
            $this->assertSame('item', $item->subject->kind);
            $this->assertSame($subtype === 'ict_cooling_fan' ? 'NO' : 'YES', $item->data_status);
            $observation = $item->observations()->firstOrFail();
            $this->assertSame(Questions::PARTS_VERSION, $observation->question_version);
            $this->assertSame($item->data_status, $observation->answers['data_status']);
            $this->assertEquals($this->fields($subtype)['answers'], $observation->answers['answers']);
            if ($subtype === 'ict_cooling_fan') {
                $this->assertNull($item->storageAsset);
            } else {
                $this->assertSame($item->subject_id, $item->storageAsset->subject_id);
                $this->assertSame($subtype, $item->storageAsset->media_kind);
            }
            $this->get('/items/'.$item->subject_id)->assertOk()->assertSee('Your part is registered. Its identity label is ready.')->assertSee($item->subtypeLabel())->assertSee('Standalone registration')->assertSee(Questions::PARTS_VERSION);
        }
        $this->assertCounts(3, 2);
    }

    public function test_out_of_scope_and_cross_category_vocabulary_cannot_register(): void
    {
        foreach ([null, 'battery', 'industrial_fan', 'mains_fan', 'ram', 'other', 'hybrid', 'router', ['hdd']] as $subtype) {
            $this->post('/items', array_replace($this->fields(), ['subtype' => $subtype]))->assertSessionHasErrors('subtype');
        }
        foreach (['laptop', 'peripheral'] as $category) {
            $this->post('/items', array_replace($this->fields(), ['category' => $category]))->assertSessionHasErrors('subtype');
        }
        $this->post('/items', array_replace($this->fields(), ['category' => 'loose_battery']))->assertSessionHasErrors('category');
        $this->assertCounts(0, 0);
    }

    public function test_fan_initial_eligibility_and_ambiguous_electronics_have_specific_errors(): void
    {
        foreach (['NO', 'UNKNOWN'] as $scope) {
            $fields = $this->fields('ict_cooling_fan');
            $fields['answers']['fan_scope'] = $scope;
            $this->from('/items/create')->post('/items', $fields)->assertSessionHasErrors(['answers.fan_scope' => Questions::FAN_SCOPE_MESSAGE]);
        }
        $fields = $this->fields('ict_cooling_fan');
        $fields['answers']['fan_electronics'] = 'ADDITIONAL';
        $this->post('/items', $fields)->assertSessionHasErrors(['answers.fan_electronics' => Questions::FAN_ELECTRONICS_MESSAGE]);
        $ambiguous = $this->register('ict_cooling_fan', ['answers' => ['fan_electronics' => 'UNKNOWN']]);
        $this->assertSame('UNKNOWN', $ambiguous->data_status);
        $this->get('/items/'.$ambiguous->subject_id)->assertSee('review')->assertDontSee('Storage identity');
        $this->assertCounts(1, 0);
    }

    public function test_part_answer_schemas_reject_missing_extra_nested_and_wrong_vocabulary(): void
    {
        foreach (['hdd', 'ssd', 'ict_cooling_fan'] as $subtype) {
            $fields = $this->fields($subtype);
            $first = array_key_first($fields['answers']);
            foreach ([[], array_merge($fields['answers'], ['parent' => null]), array_merge($fields['answers'], [$first => ['UNKNOWN']]), array_merge($fields['answers'], [$first => 'N/A'])] as $answers) {
                $this->post('/items', array_replace($fields, ['answers' => $answers]))->assertSessionHasErrors();
            }
        }
        $fields = $this->fields('ssd');
        $fields['answers']['connector_damage'] = 'PASS';
        $this->post('/items', $fields)->assertSessionHasErrors('answers.connector_damage');
        $fields['answers']['connector_damage'] = 'NO';
        $fields['answers']['previous_operation'] = 'YES';
        $this->post('/items', $fields)->assertSessionHasErrors('answers.previous_operation');
        $this->assertCounts(0, 0);
    }

    public function test_drive_revisions_keep_data_yes_and_append_versioned_history(): void
    {
        foreach (['hdd', 'ssd'] as $subtype) {
            $item = $this->register($subtype);
            $fields = $this->revision($item);
            foreach (['NO', 'UNKNOWN', 'YES', null, []] as $status) {
                $this->patch('/items/'.$item->subject_id.'/observations', array_merge($fields, ['data_status' => $status]))->assertSessionHasErrors('data_status')->assertSessionMissing('_old_input.data_status');
            }
            $fields['reason'] = 'Previously unused or erased is not sanitization evidence.';
            $this->patch('/items/'.$item->subject_id.'/observations', $fields)->assertRedirect('/items/'.$item->subject_id);
            $this->evaluateFacets();
            $this->assertSame('YES', $item->refresh()->data_status);
            $this->assertSame(2, $item->observations()->count());
            foreach ($item->observations as $observation) {
                $this->assertSame('YES', $observation->answers['data_status']);
                $this->assertSame(Questions::PARTS_VERSION, $observation->question_version);
            }
        }
        $this->assertDatabaseCount('storage_assets', 2);
    }

    public function test_fan_revision_honestly_records_new_uncertainty_without_losing_history(): void
    {
        $item = $this->register('ict_cooling_fan');
        $fields = $this->revision($item);
        $fields['answers']['fan_scope'] = 'NO';
        $fields['answers']['fan_electronics'] = 'ADDITIONAL';
        $this->patch('/items/'.$item->subject_id.'/observations', $fields)->assertRedirect();
        $this->patch('/items/'.$item->subject_id.'/observations', $fields)->assertRedirect();
        $this->evaluateFacets();
        $this->assertSame('UNKNOWN', $item->refresh()->data_status);
        $this->assertSame(2, $item->subject->refresh()->version);
        $history = $item->observations()->orderBy('revision')->get();
        $this->assertCount(2, $history);
        $this->assertSame('BASIC', $history[0]->answers['answers']['fan_electronics']);
        $this->assertSame('ADDITIONAL', $history[1]->answers['answers']['fan_electronics']);
        $this->get('/items/'.$item->subject_id)->assertOk()->assertSee('review')->assertSee('Additional');
        $stale = $fields;
        $stale['idempotency_key'] = Str::uuid()->toString();
        $this->patch('/items/'.$item->subject_id.'/observations', $stale)->assertStatus(409);
        $new = $this->revision($item->load('subject'));
        $new['answers']['fan_scope'] = 'UNKNOWN';
        $new['answers']['fan_electronics'] = 'UNKNOWN';
        $this->patch('/items/'.$item->subject_id.'/observations', $new)->assertRedirect();
        $this->assertSame('UNKNOWN', $item->refresh()->data_status);
        $this->assertDatabaseCount('storage_assets', 0);
    }

    public function test_serial_only_parts_preserve_global_normalization_encryption_and_private_duplicates(): void
    {
        $item = $this->register('hdd', ['identifier' => ' 00-SYNTH-PART ', 'model' => 'Private existing model']);
        $this->assertSame('00-SYNTH-PART', Crypt::decryptString($item->identifier_ciphertext));
        $this->assertSame(Identifiers::digest('00SYNTHPART'), $item->identifier_hmac);
        $this->assertSame('serial', $item->identifier_kind);
        $other = User::factory()->create();
        $this->signIn($other);
        $duplicate = array_merge($this->fields('ssd'), ['identifier' => "00 synth\tpart", 'model' => 'Safe retained model', 'photo' => UploadedFile::fake()->image('rejected.png')]);
        $this->from('/items/create')->post('/items', $duplicate)->assertRedirect('/items/create')->assertSessionHasErrors(['identifier' => Identifiers::DUPLICATE_MESSAGE])->assertSessionMissing('_old_input.identifier');
        $response = $this->withCookie(config('session.cookie'), session()->getId())->get('/items/create')->assertOk()->assertSee('Safe retained model')->assertSee(Identifiers::DUPLICATE_MESSAGE);
        foreach ([$item->subject_id, $item->subject->public_code, 'Private existing model', $this->owner->email, '00-SYNTH-PART'] as $private) {
            $response->assertDontSee($private);
        }
        $this->assertCounts(1, 1);
        $whole = ['category' => 'desktop', 'idempotency_key' => Str::uuid()->toString(), 'physical_damage' => 'UNKNOWN', 'safety_concern' => 'UNKNOWN', 'data_status' => 'UNKNOWN', 'reason' => 'Synthetic device', 'identifier' => '00SYNTHPART', 'answers' => array_fill_keys(array_keys(Questions::tests('desktop', null)), 'UNKNOWN')];
        $this->post('/items', $whole)->assertSessionHasErrors('identifier');
        foreach (['', '260531793113837'] as $value) {
            $this->post('/items', array_merge($this->fields(), ['identifier_kind' => 'imei', 'identifier' => $value]))->assertSessionHasErrors('identifier_kind')->assertSessionMissing('_old_input.identifier_kind');
        }
    }

    public function test_numeric_part_serial_shares_whole_device_imei_namespace(): void
    {
        // ETSI TS 123 003 Annex B.3 public checksum example, not an allocated private identifier.
        $imei = '260531793113837';
        $whole = ['category' => 'phone', 'idempotency_key' => Str::uuid()->toString(), 'physical_damage' => 'UNKNOWN', 'safety_concern' => 'UNKNOWN', 'data_status' => 'YES', 'reason' => 'Synthetic phone', 'identifier_kind' => 'imei', 'identifier' => $imei, 'answers' => array_fill_keys(array_keys(Questions::tests('phone', null)), 'UNKNOWN')];
        $this->post('/items', $whole)->assertSessionHasNoErrors();
        $this->post('/items', array_merge($this->fields('ssd'), ['identifier' => $imei]))->assertSessionHasErrors(['identifier' => Identifiers::DUPLICATE_MESSAGE]);
        $this->assertCounts(1, 0);
    }

    public function test_unknown_serials_are_distinct_and_successful_photo_retries_are_idempotent(): void
    {
        foreach ([null, '', ' - '] as $identifier) {
            $item = $this->register('ssd', ['identifier' => $identifier]);
            $this->assertNull($item->identifier_ciphertext);
            $this->assertNull($item->identifier_hmac);
            $this->assertNull($item->identifier_kind);
        }
        $fields = array_merge($this->fields(), ['identifier' => 'SYNTH-RETRY', 'photo' => UploadedFile::fake()->image('synthetic.png')]);
        $first = $this->post('/items', $fields)->assertSessionHasNoErrors();
        $this->post('/items', $fields)->assertRedirect($first->headers->get('Location'))->assertSessionHasNoErrors();
        $this->evaluateFacets();
        $this->post('/items', array_merge($fields, ['reason' => 'Changed']))->assertStatus(409);
        $this->post('/items', array_merge($fields, ['idempotency_key' => Str::uuid()->toString()]))->assertSessionHasErrors('identifier');
        $this->assertCounts(4, 4, 1);
    }

    public function test_forged_origin_storage_and_unknown_fields_reject_presence_and_never_flash_private_input(): void
    {
        $protected = ['parent', 'parent_id', 'parent_item_id', 'parent_subject_id', 'parent_links', 'removed_from', 'origin_kind', 'detachment_event_id', 'batch_id', 'storage_id', 'storage_subject_id', 'storage_assets', 'embedded', 'quantity', 'intake_class', 'owner_user_id', 'identifier_ciphertext', 'identifier_hmac', 'identifier_key_version', 'role', 'unexpected'];
        foreach ($protected as $field) {
            foreach ([null, ['private-reference'], 'private-reference'] as $value) {
                $this->travel(61)->seconds();
                $fields = array_merge($this->fields(), [$field => $value, 'identifier' => 'PRIVATE-SYNTH', 'model' => 'Safe model', 'password' => 'private-password']);
                $this->from('/items/create')->post('/items', $fields)->assertSessionHasErrors($field)->assertSessionMissing('_old_input.'.$field)->assertSessionMissing('_old_input.identifier')->assertSessionMissing('_old_input.password')->assertSessionHas('_old_input.model', 'Safe model');
            }
        }
        $this->assertCounts(0, 0);
        $this->withCookie(config('session.cookie'), session()->getId())->get('/items/create')->assertOk()
            ->assertSee('href="#registration-heading"', false)->assertDontSee('private-reference')->assertDontSee('PRIVATE-SYNTH')->assertDontSee('private-password');
        $this->post('/items', array_merge($this->fields(), ['model' => [], 'reason' => [], 'answers' => ['previous_operation' => []]]))->assertSessionHasErrors()->assertSessionMissing('_old_input.model')->assertSessionMissing('_old_input.reason')->assertSessionMissing('_old_input.answers.previous_operation');
    }

    public function test_existing_devices_and_part_revisions_also_reject_forged_parent_fields(): void
    {
        $part = $this->register('ssd');
        foreach (['parent_id', 'storage_assets', 'origin_kind', 'identifier_key_version', 'model'] as $field) {
            $this->patch('/items/'.$part->subject_id.'/observations', array_merge($this->revision($part), [$field => null]))->assertSessionHasErrors($field)->assertSessionMissing('_old_input.'.$field);
        }
        $whole = ['category' => 'laptop', 'idempotency_key' => Str::uuid()->toString(), 'physical_damage' => 'UNKNOWN', 'safety_concern' => 'UNKNOWN', 'data_status' => 'UNKNOWN', 'reason' => 'Synthetic device', 'answers' => array_fill_keys(array_keys(Questions::tests('laptop', null)), 'UNKNOWN')];
        foreach (['parent_id', 'origin_kind', 'storage_assets'] as $field) {
            $this->post('/items', array_merge($whole, [$field => null]))->assertSessionHasErrors($field);
        }
        $this->assertCounts(1, 1);
    }

    public function test_deferred_reciprocal_constraints_reject_missing_mismatched_and_non_drive_facets(): void
    {
        $base = $this->register('hdd');
        $row = (array) DB::table('items')->where('subject_id', $base->subject_id)->first();
        foreach (['missing', 'mismatch', 'fan', 'whole', 'orphan'] as $case) {
            try {
                DB::transaction(function () use ($row, $case): void {
                    $id = Str::uuid()->toString();
                    DB::table('subjects')->insert(['id' => $id, 'public_code' => 'EWC-'.strtoupper(bin2hex(random_bytes(16))), 'kind' => 'item', 'version' => 1, 'created_at' => now()]);
                    $copy = $row;
                    $copy['subject_id'] = $id;
                    if ($case === 'fan') {
                        $copy['subtype'] = 'ict_cooling_fan';
                    } elseif ($case === 'whole') {
                        $copy['category_id'] = DB::table('categories')->where('code', 'laptop')->value('id');
                        $copy['subtype'] = null;
                        $copy['intake_class'] = 'whole_device';
                    }
                    if ($case !== 'orphan') {
                        DB::table('items')->insert($copy);
                        $this->assertDatabaseHas('items', ['subject_id' => $id]);
                    }
                    if ($case !== 'missing') {
                        DB::table('storage_assets')->insert(['subject_id' => $id, 'media_kind' => $case === 'mismatch' ? 'ssd' : 'hdd']);
                        $this->assertDatabaseHas('storage_assets', ['subject_id' => $id]);
                    }
                    $this->evaluateFacets();
                });
                $this->fail('Deferred reciprocal constraints must reject '.$case.' at explicit enforcement.');
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->errorInfo[0]);
            }
            $this->assertCounts(1, 1);
        }
        $triggers = DB::select("SELECT tgdeferrable, tginitdeferred FROM pg_trigger WHERE tgname IN ('complete_item_storage','complete_storage_item')");
        $this->assertCount(2, $triggers);
        foreach ($triggers as $trigger) {
            $this->assertTrue($trigger->tgdeferrable);
            $this->assertTrue($trigger->tginitdeferred);
        }
    }

    public function test_storage_identity_and_drive_data_cannot_be_changed_by_direct_sql(): void
    {
        $item = $this->register('ssd');
        foreach (['NO', 'UNKNOWN'] as $status) {
            $this->databaseFailure(fn () => DB::table('items')->where('subject_id', $item->subject_id)->update(['data_status' => $status]), '23514');
        }
        $this->databaseFailure(fn () => DB::table('storage_assets')->where('subject_id', $item->subject_id)->delete(), '23514');
        $this->databaseFailure(fn () => DB::table('storage_assets')->where('subject_id', $item->subject_id)->update(['media_kind' => 'hdd']), '23514');
        $this->databaseFailure(fn () => DB::table('storage_assets')->where('subject_id', $item->subject_id)->update(['subject_id' => Str::uuid()->toString()]), '23514');
        foreach (['intake_class' => 'whole_device', 'subtype' => 'hdd', 'category_id' => DB::table('categories')->where('code', 'laptop')->value('id')] as $field => $value) {
            $this->databaseFailure(fn () => DB::table('items')->where('subject_id', $item->subject_id)->update([$field => $value]), 'P0001');
        }
        $this->evaluateFacets();
        $this->assertCounts(1, 1);
        $this->assertSame('YES', $item->refresh()->data_status);
    }

    public function test_direct_duplicate_drive_insert_is_blocked_by_the_global_database_index(): void
    {
        $item = $this->register('ssd', ['identifier' => 'SYNTH-DIRECT-PART']);
        $row = (array) DB::table('items')->where('subject_id', $item->subject_id)->first();
        $row['subject_id'] = Str::uuid()->toString();
        $row['owner_user_id'] = User::factory()->create()->id;
        $row['subtype'] = 'hdd';
        try {
            DB::transaction(function () use ($row): void {
                DB::table('subjects')->insert(['id' => $row['subject_id'], 'public_code' => 'EWC-'.strtoupper(bin2hex(random_bytes(16))), 'kind' => 'item', 'version' => 1, 'created_at' => now()]);
                DB::table('items')->insert($row);
                DB::table('storage_assets')->insert(['subject_id' => $row['subject_id'], 'media_kind' => 'hdd']);
                $this->evaluateFacets();
            });
            $this->fail('A competing writer must not bypass the global index.');
        } catch (QueryException $exception) {
            $this->assertTrue(Identifiers::isDuplicateConflict($exception));
        }
        $this->assertCounts(1, 1);
    }

    public function test_named_identifier_conflict_after_photo_write_maps_safely_and_cleans_the_facet_and_blob(): void
    {
        $cause = new \PDOException('Synthetic uniqueness conflict');
        $cause->errorInfo = ['23505', 7, 'duplicate key value violates unique constraint "'.Identifiers::UNIQUE_INDEX.'"'];
        DB::listen(function ($query) use ($cause): void {
            if (str_starts_with($query->sql, 'insert into "evidence_links"')) {
                throw new QueryException('pgsql', 'synthetic write', [], $cause);
            }
        });
        try {
            app(RegisterDevice::class)->register($this->owner, array_merge($this->fields(), ['identifier' => 'SYNTH-RACE-PART']), UploadedFile::fake()->image('synthetic.png'));
            $this->fail('A specific identifier conflict must become a field error.');
        } catch (ValidationException $exception) {
            $this->assertSame(['identifier' => [Identifiers::DUPLICATE_MESSAGE]], $exception->errors());
        }
        $this->assertCounts(0, 0);
    }

    private function databaseFailure(callable $write, string $state): void
    {
        try {
            DB::transaction($write);
            $this->fail('A direct persistence violation must fail.');
        } catch (QueryException $exception) {
            $this->assertSame($state, $exception->errorInfo[0]);
        }
    }

    public function test_failures_at_facet_event_custody_and_photo_boundaries_leave_no_partial_records(): void
    {
        foreach (['storage_assets', 'domain_events', 'custody_current', 'evidence_links'] as $table) {
            $active = true;
            DB::listen(function ($query) use ($table, &$active): void {
                if ($active && str_starts_with($query->sql, 'insert into "'.$table.'"')) {
                    throw new RuntimeException('Synthetic atomicity failure.');
                }
            });
            try {
                app(RegisterDevice::class)->register($this->owner, $this->fields(), UploadedFile::fake()->image('synthetic.png'));
                $this->fail('Expected an injected persistence failure.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Synthetic atomicity failure.', $exception->getMessage());
            } finally {
                $active = false;
            }
            $this->assertCounts(0, 0);
        }
    }

    public function test_owned_part_views_lookup_labels_qr_and_photos_obey_access_boundaries(): void
    {
        $item = $this->register('ssd', ['photo' => UploadedFile::fake()->image('private.png'), 'reason' => '<script>synthetic</script>', 'model' => '<b>synthetic model</b>']);
        $attachment = DB::table('evidence_links')->where('subject_id', $item->subject_id)->value('attachment_id');
        $paths = ['/items/'.$item->subject_id, '/items/'.$item->subject_id.'/edit', '/subjects/'.$item->subject_id.'/label', '/subjects/'.$item->subject_id.'/qr', '/items/'.$item->subject_id.'/photos/'.$attachment];
        foreach ($paths as $path) {
            $this->get($path)->assertOk();
        }
        $qr = $this->get('/subjects/'.$item->subject_id.'/qr')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame(route('items.lookup', $item->subject->public_code), (new QrReader($qr->getContent(), QrReader::SOURCE_TYPE_BLOB))->text(['TRY_HARDER' => true]));
        $this->get('/items')->assertOk()->assertSee('Solid-state drive (SSD)')->assertSee('&lt;b&gt;synthetic model&lt;/b&gt;', false);
        $this->get('/items/'.$item->subject_id)->assertSee('&lt;script&gt;synthetic&lt;/script&gt;', false)->assertDontSee('<script>synthetic</script>', false)->assertDontSee('Parent device');
        $this->get('/subjects/'.$item->subject_id.'/label')->assertSee('Part identity')->assertSee('Solid-state drive (SSD)');
        $this->get('/items/lookup?public_code='.$item->subject->public_code)->assertRedirect('/items/'.$item->subject_id);
        $this->get('/lookup/'.$item->subject->public_code)->assertRedirect('/items/'.$item->subject_id);
        $this->signIn(User::factory()->create());
        foreach ($paths as $path) {
            $this->get($path)->assertNotFound();
        }
        $this->get('/lookup/'.$item->subject->public_code)->assertNotFound();
        $this->patch('/items/'.$item->subject_id.'/observations', $this->revision($item))->assertNotFound();
        foreach ([Role::Collector, Role::Processor, Role::Administrator] as $role) {
            $staff = User::factory()->create();
            $staff->membership()->update(['role' => $role->value]);
            $this->signIn($staff);
            $this->get('/items/create?category=standalone_part&subtype=ssd')->assertForbidden();
            $this->post('/items', $this->fields())->assertForbidden();
        }
        $this->signIn($this->owner);
        $this->withSession(['auth_session_version' => $this->owner->session_version - 1])->get('/items/'.$item->subject_id)->assertRedirect('/login');
        $this->get('/items/create?category=standalone_part&subtype=hdd')->assertRedirect('/login');
        $this->signIn($this->owner);
        $this->owner->forceFill(['status' => 'suspended'])->save();
        $this->post('/items', $this->fields())->assertRedirect('/login');
    }

    public function test_part_form_errors_use_existing_summary_and_input_associations_with_safe_redisplay(): void
    {
        $fields = $this->fields('ict_cooling_fan');
        $fields['answers']['fan_scope'] = 'UNKNOWN';
        $fields['identifier'] = 'PRIVATE-SYNTH';
        $this->from('/items/create')->post('/items', $fields)->assertRedirect('/items/create');
        $response = $this->withCookie(config('session.cookie'), session()->getId())->get('/items/create')->assertOk()->assertSee(Questions::FAN_SCOPE_MESSAGE)->assertSee('24 V DC')->assertSee('140 mm')->assertSee('Do not power')->assertDontSee('PRIVATE-SYNTH');
        $dom = new DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//*[@data-error-summary][@role="alert"][@tabindex="-1"]')->length);
        $input = $xpath->query('//select[@id="answers_fan_scope"][@aria-invalid="true"]')->item(0);
        $this->assertNotNull($input);
        $this->assertStringContainsString('answers_fan_scope-error', $input->getAttribute('aria-describedby'));
        $this->assertSame(1, $xpath->query('//a[@href="#answers_fan_scope"]')->length);
        $this->assertSame(0, $xpath->query('//*[@name="data_status"]')->length);
        $this->assertSame(0, $xpath->query('//*[@name="identifier_kind"]')->length);
        $this->assertSame(1, $xpath->query('//option[@value="UNKNOWN"][@selected]/parent::select[@id="answers_fan_scope"]')->length);
        $this->assertSame('', $xpath->query('//input[@id="identifier"]')->item(0)->getAttribute('value'));
    }

    public function test_invalid_get_selections_show_guidance_without_loading_a_wrong_form(): void
    {
        foreach (['category=standalone_part&subtype=battery', 'category=standalone_part&subtype[]=ssd', 'category[]=standalone_part', 'category=laptop&subtype=hdd'] as $query) {
            $this->get('/items/create?'.$query)->assertOk()->assertSee('Choose a supported category')->assertDontSee('enctype="multipart/form-data"', false);
        }
        foreach (['hdd', 'ssd', 'ict_cooling_fan'] as $subtype) {
            $this->get('/items/create?category=standalone_part&subtype='.$subtype)->assertOk()->assertSee('Register a standalone part')->assertSee('Register part and create label');
        }
    }
}
