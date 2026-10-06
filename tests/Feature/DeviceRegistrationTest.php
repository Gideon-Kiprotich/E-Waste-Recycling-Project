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
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Zxing\QrReader;

class DeviceRegistrationTest extends TestCase
{
    use DatabaseTransactions;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1]);
        Storage::fake('device_photos');
        $this->owner = User::factory()->create(['email' => 'device-owner@example.test']);
        $this->signIn($this->owner);
    }

    private function signIn(User $user): void
    {
        $this->actingAs($user)->withSession(['auth_session_version' => $user->session_version]);
    }

    private function fields(string $category = 'laptop', ?string $subtype = null): array
    {
        $fields = ['category' => $category, 'idempotency_key' => Str::uuid()->toString(), 'data_status' => 'UNKNOWN', 'safety_concern' => 'UNKNOWN', 'physical_damage' => 'UNKNOWN', 'reason' => 'Synthetic self-report: not tested.', 'answers' => array_fill_keys(array_keys(Questions::tests($category, $subtype)), 'UNKNOWN')];
        if ($subtype) {
            $fields['subtype'] = $subtype;
        }

        return $fields;
    }

    private function register(array $extra = []): Item
    {
        $response = $this->post('/items', array_merge($this->fields(), $extra))->assertRedirect();

        return Item::with('subject', 'category')->findOrFail(basename($response->headers->get('Location')));
    }

    private function revision(Item $item): array
    {
        $fields = $this->fields($item->category->code, $item->subtype);
        unset($fields['category'], $fields['subtype']);
        $fields['expected_version'] = $item->subject->version;

        return $fields;
    }

    public function test_all_seven_supported_category_subtypes_and_unknown_values_register_atomically(): void
    {
        foreach (['laptop', 'desktop', 'phone', 'flat_monitor', 'printer', 'router', 'keyboard'] as $code) {
            $peripheral = in_array($code, ['router', 'keyboard']);
            $fields = $this->fields($peripheral ? 'peripheral' : $code, $peripheral ? $code : null);
            $response = $this->post('/items', $fields)->assertRedirect()->assertSessionHas('status', 'Your device is registered. Its identity label is ready.');
            $item = Item::with('subject', 'category')->findOrFail(basename($response->headers->get('Location')));
            $this->assertTrue(Str::isUuid($item->subject_id));
            $this->assertMatchesRegularExpression('/^EWC-[A-F0-9]{32}$/', $item->subject->public_code);
            $this->assertNull($item->identifier_hmac);
            $this->assertNull($item->model);
            $this->assertSame('registered', $item->processing_state);
            $this->assertSame('UNKNOWN', $item->data_status);
            $this->assertEquals($fields['answers'], $item->observations()->first()->answers['answers']);
            $response->assertRedirect(route('items.show', $item->subject_id));
            $this->get(route('items.show', $item->subject_id))->assertOk()->assertSee('Your device is registered.')->assertSee('feedback--success')->assertSee('Not technician inspection');
            $this->assertDatabaseHas('custody_current', ['subject_id' => $item->subject_id, 'version' => 1]);
        }
        $this->assertDatabaseCount('subjects', 7);
        foreach (['items', 'item_observations', 'domain_events', 'commands', 'custody_current', 'notification_outbox'] as $table) {
            $this->assertDatabaseCount($table, 7);
        }
        $this->assertSame(7, DB::table('subjects')->distinct()->count('public_code'));
    }

    public function test_excluded_categories_subtypes_missing_answers_and_injection_are_rejected(): void
    {
        foreach (['refrigerator', 'air_conditioner', 'crt', 'medical', 'industrial', 'loose_battery', 'switch', 'ups'] as $category) {
            $this->post('/items', array_merge($this->fields(), ['category' => $category]))->assertSessionHasErrors('category');
        }
        foreach ([null, 'switch', 'ups'] as $subtype) {
            $this->post('/items', array_merge($this->fields(), ['category' => 'peripheral', 'subtype' => $subtype]))->assertSessionHasErrors('subtype');
        }
        foreach (['owner_user_id', 'subject_id', 'public_code', 'processing_state', 'status', 'observer_id', 'source', 'events', 'identifier_ciphertext', 'role'] as $field) {
            $this->post('/items', array_merge($this->fields(), [$field => 'injected']))->assertSessionHasErrors($field);
            $this->travel(61)->seconds();
        }
        $fields = $this->fields();
        unset($fields['answers']['power']);
        $this->post('/items', $fields)->assertSessionHasErrors('answers.power');
        $this->post('/items', array_merge($this->fields(), ['data_status' => 'safe', 'safety_concern' => 'healthy', 'answers' => ['power' => 'NOT_APPLICABLE']]))->assertSessionHasErrors(['data_status', 'safety_concern', 'answers']);
        $this->assertDatabaseCount('items', 0);
    }

    public function test_same_payload_retry_returns_first_identity_and_changed_payload_conflicts(): void
    {
        $fields = array_merge($this->fields(), ['identifier' => 'SYNTH-001', 'photo' => UploadedFile::fake()->image('synthetic.jpg', 12, 12)]);
        $first = $this->post('/items', $fields)->assertRedirect();
        $retry = $this->post('/items', $fields)->assertRedirect();
        $this->assertSame($first->headers->get('Location'), $retry->headers->get('Location'));
        $this->post('/items', array_merge($fields, ['reason' => 'Changed payload']))->assertStatus(409)->assertSee('submission reference');
        foreach (['subjects', 'items', 'item_observations', 'domain_events', 'commands', 'custody_current', 'notification_outbox', 'attachments', 'evidence_links'] as $table) {
            $this->assertDatabaseCount($table, 1);
        }
        $this->assertCount(1, Storage::disk('device_photos')->allFiles());
    }

    public function test_duplicate_rejection_is_global_private_atomic_and_accessible(): void
    {
        $other = User::factory()->create(['email' => 'private-owner@example.test', 'display_name' => 'Private Other Owner']);
        $this->signIn($other);
        $existing = $this->register(['identifier' => 'SYNTH-001', 'model' => 'Private other model', 'photo' => UploadedFile::fake()->image('first.png')]);
        $this->assertSame('SYNTH-001', Crypt::decryptString($existing->identifier_ciphertext));
        $this->assertNotSame('SYNTH-001', $existing->identifier_ciphertext);
        $this->assertSame(Identifiers::KEY_VERSION, $existing->identifier_key_version);
        $this->assertSame('serial', $existing->identifier_kind);
        foreach ([$other, $this->owner] as $consumer) {
            $this->signIn($consumer);
            foreach (['laptop', 'desktop'] as $category) {
                $fields = array_merge($this->fields($category), ['identifier' => 'synth 001', 'identifier_kind' => 'serial', 'model' => 'Safe retained model', 'photo' => UploadedFile::fake()->image('rejected.png')]);
                $this->from('/items/create')->post('/items', $fields)->assertRedirect('/items/create')
                    ->assertSessionHasErrors(['identifier' => Identifiers::DUPLICATE_MESSAGE])->assertSessionMissing('_old_input.identifier');
            }
        }
        $response = $this->withCookie(config('session.cookie'), session()->getId())->get('/items/create')->assertOk()
            ->assertSee(Identifiers::DUPLICATE_MESSAGE)->assertSee('Safe retained model')->assertSee('data-error-summary', false);
        foreach (['Private Other Owner', $other->email, 'Private other model', $existing->subject->public_code, $existing->subject_id, 'synth 001', 'SYNTH-001'] as $private) {
            $response->assertDontSee($private);
        }
        $dom = new DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//input[@id="identifier"][@aria-invalid="true"]')->length);
        $this->assertStringContainsString('identifier-error', $xpath->query('//input[@id="identifier"]')->item(0)->getAttribute('aria-describedby'));
        $this->assertSame('', $xpath->query('//input[@id="identifier"]')->item(0)->getAttribute('value'));
        $this->assertGreaterThanOrEqual(1, $xpath->query('//a[@href="#identifier"]')->length);
        $this->assertSame(1, $xpath->query('//*[@role="alert"]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-error-summary][@tabindex="-1"]')->length);
        $this->assertRegistrationCounts(1);
        $this->assertCount(1, Storage::disk('device_photos')->allFiles());
        $events = DB::table('domain_events')->get()->toJson();
        $this->assertStringNotContainsString('SYNTH-001', $events);
        $this->assertStringNotContainsString('possible_duplicate', $events);
    }

    public function test_normalization_preserves_leading_zeroes_and_blocks_equivalent_serials(): void
    {
        $item = $this->register(['identifier' => ' 00-Ab-12 ']);
        $this->assertSame(Identifiers::digest('00AB12'), $item->identifier_hmac);
        foreach (['00ab12', '00 AB 12', "00-ab\t12"] as $identifier) {
            $this->post('/items', array_merge($this->fields(), ['identifier' => $identifier]))->assertSessionHasErrors(['identifier' => Identifiers::DUPLICATE_MESSAGE]);
        }
        $this->register(['identifier' => '0AB12']);
        $this->assertDatabaseCount('items', 2);
    }

    public function test_optional_and_unknown_identifiers_do_not_conflict(): void
    {
        foreach ([[], ['identifier' => null], ['identifier' => ''], ['identifier' => ' - '], ['identifier_kind' => 'imei', 'identifier' => '']] as $extra) {
            $item = $this->register($extra);
            foreach (['identifier_kind', 'identifier_hmac', 'identifier_ciphertext', 'identifier_key_version'] as $field) {
                $this->assertNull($item->$field);
            }
        }
        $this->assertDatabaseCount('items', 5);
    }

    private function exampleImei(): string
    {
        // Public computation example, not an invented/allocated device: ETSI TS 123 003 V15.10.0 Annex B.3.
        return '260531793113837';
    }

    public function test_imei_has_explicit_type_format_checksum_and_shared_serial_namespace(): void
    {
        $imei = $this->exampleImei();
        $fields = array_merge($this->fields('phone'), ['identifier_kind' => 'imei', 'identifier' => substr($imei, 0, 8).'-'.substr($imei, 8)]);
        $first = $this->post('/items', $fields)->assertRedirect();
        $this->post('/items', $fields)->assertRedirect($first->headers->get('Location'));
        $item = Item::firstOrFail();
        $this->assertSame('imei', $item->identifier_kind);
        $this->assertSame(Identifiers::digest($imei), $item->identifier_hmac);
        $this->get($this->detail($item))->assertOk()->assertSee('Private IMEI recorded')->assertDontSee($imei);
        $this->post('/items', array_merge($this->fields(), ['identifier_kind' => 'serial', 'identifier' => $imei]))->assertSessionHasErrors(['identifier' => Identifiers::DUPLICATE_MESSAGE]);
        $this->post('/items', array_merge($fields, ['idempotency_key' => Str::uuid()->toString()]))->assertSessionHasErrors(['identifier' => Identifiers::DUPLICATE_MESSAGE]);
        $this->assertRegistrationCounts(1, 0);
    }

    public function test_invalid_imei_serial_and_type_input_are_rejected_without_writes(): void
    {
        $imei = $this->exampleImei();
        foreach ([substr($imei, 0, 14), $imei.'0', substr($imei, 0, 14).'8', 'SYNTH-IMEI', [$imei]] as $identifier) {
            $this->post('/items', array_merge($this->fields('phone'), ['identifier_kind' => 'imei', 'identifier' => $identifier]))->assertSessionHasErrors('identifier')->assertSessionMissing('_old_input.identifier');
        }
        foreach (['bad/type', 'é123', str_repeat('A', 101)] as $identifier) {
            $this->post('/items', array_merge($this->fields(), ['identifier_kind' => 'serial', 'identifier' => $identifier]))->assertSessionHasErrors('identifier');
        }
        foreach (['legacy_unspecified', 'manufacturer', ['serial']] as $kind) {
            $this->post('/items', array_merge($this->fields(), ['identifier_kind' => $kind]))->assertSessionHasErrors('identifier_kind');
        }
        $this->assertRegistrationCounts(0, 0);
    }

    public function test_database_index_rejects_direct_duplicate_insert_across_owner_category_and_key_version(): void
    {
        $existing = $this->register(['identifier' => 'SYNTH-DB']);
        $other = User::factory()->create();
        $row = (array) DB::table('items')->where('subject_id', $existing->subject_id)->first();
        $row['subject_id'] = Str::uuid()->toString();
        $row['owner_user_id'] = $other->id;
        $row['category_id'] = DB::table('categories')->where('code', 'desktop')->value('id');
        $row['identifier_key_version'] = 'synthetic-other-key-version';
        try {
            DB::transaction(function () use ($row): void {
                DB::table('subjects')->insert(['id' => $row['subject_id'], 'public_code' => 'EWC-'.strtoupper(bin2hex(random_bytes(16))), 'kind' => 'item', 'version' => 1, 'created_at' => now()]);
                DB::table('items')->insert($row);
            });
            $this->fail('The unique index must reject a direct writer that bypasses application locks.');
        } catch (QueryException $exception) {
            $this->assertTrue(Identifiers::isDuplicateConflict($exception));
        }
        $this->assertRegistrationCounts(1, 0);
    }

    public function test_named_unique_conflict_rolls_back_all_records_and_private_photo(): void
    {
        DB::listen(function ($query): void {
            if (str_starts_with($query->sql, 'insert into "evidence_links"')) {
                throw $this->databaseConflict(Identifiers::UNIQUE_INDEX);
            }
        });
        try {
            app(RegisterDevice::class)->register($this->owner, array_merge($this->fields(), ['identifier' => 'SYNTH-RACE']), UploadedFile::fake()->image('rejected.png'));
            $this->fail('Expected a field error for the named unique constraint.');
        } catch (ValidationException $exception) {
            $this->assertSame(['identifier' => [Identifiers::DUPLICATE_MESSAGE]], $exception->errors());
        }
        $this->assertRegistrationCounts(0, 0);
        $this->assertCount(0, Storage::disk('device_photos')->allFiles());
    }

    public function test_unrelated_database_conflicts_are_not_converted_or_suppressed(): void
    {
        $failure = $this->databaseConflict('subjects_public_code_unique');
        $this->assertFalse(Identifiers::isDuplicateConflict($failure));
        DB::listen(function ($query) use ($failure): void {
            if (str_starts_with($query->sql, 'insert into "items"')) {
                throw $failure;
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->post('/items', $this->fields());
            $this->fail('An unrelated database error must propagate.');
        } catch (QueryException $exception) {
            $this->assertSame($failure, $exception);
        }
        $this->assertRegistrationCounts(0, 0);
    }

    private function databaseConflict(string $constraint): QueryException
    {
        $cause = new \PDOException('Synthetic unique conflict');
        $cause->errorInfo = ['23505', 7, 'duplicate key value violates unique constraint "'.$constraint.'"'];

        return new QueryException('pgsql', 'synthetic write', [], $cause);
    }

    private function assertRegistrationCounts(int $records, int $photos = 1): void
    {
        foreach (['subjects', 'items', 'item_observations', 'domain_events', 'commands', 'custody_current', 'notification_outbox'] as $table) {
            $this->assertDatabaseCount($table, $records);
        }
        foreach (['attachments', 'evidence_links'] as $table) {
            $this->assertDatabaseCount($table, $photos);
        }
    }

    public function test_owner_isolation_covers_every_device_and_photo_route(): void
    {
        $item = $this->register(['model' => 'Private device model', 'photo' => UploadedFile::fake()->image('device.png')]);
        $attachment = DB::table('attachments')->value('id');
        $other = User::factory()->create();
        $this->signIn($other);
        $this->get('/items')->assertOk()->assertDontSee('Private device model')->assertDontSee($item->subject->public_code);
        foreach ([$this->detail($item), route('items.edit', $item->subject_id), route('items.lookup', $item->subject->public_code), route('items.label', $item->subject_id), route('items.qr', $item->subject_id), route('items.photo', [$item->subject_id, $attachment])] as $url) {
            $this->get($url)->assertNotFound();
        }
        $this->patch(route('items.update', $item->subject_id), $this->revision($item))->assertNotFound();
        $this->get('/items/lookup?public_code='.$item->subject->public_code)->assertNotFound();
        $this->get('/items/not-a-uuid')->assertNotFound();
    }

    private function detail(Item $item): string
    {
        return route('items.show', $item->subject_id);
    }

    public function test_guests_and_all_staff_roles_have_no_device_access(): void
    {
        $item = $this->register(['photo' => UploadedFile::fake()->image('device.png')]);
        $attachment = DB::table('attachments')->value('id');
        $urls = ['/items', '/items/create', $this->detail($item), route('items.edit', $item->subject_id), route('items.lookup', $item->subject->public_code), route('items.label', $item->subject_id), route('items.qr', $item->subject_id), route('items.photo', [$item->subject_id, $attachment])];
        foreach ([Role::Collector, Role::Processor, Role::Administrator] as $role) {
            $staff = User::factory()->create();
            $staff->membership->forceFill(['role' => $role])->save();
            $staff->unsetRelation('membership');
            $this->signIn($staff);
            foreach ($urls as $url) {
                $this->get($url)->assertForbidden();
            }
            $this->post('/items', $this->fields())->assertForbidden();
            $this->patch(route('items.update', $item->subject_id), $this->revision($item))->assertForbidden();
        }
        auth()->forgetGuards();
        $this->app['session']->flush();
        foreach ($urls as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
        $this->post('/items', $this->fields())->assertRedirect(route('login'));
    }

    public function test_revisions_preserve_history_retry_safely_and_reject_stale_or_committed_edits(): void
    {
        $item = $this->register();
        $fields = array_merge($this->revision($item), ['data_status' => 'YES', 'reason' => 'Synthetic updated observation']);
        $this->patch(route('items.update', $item->subject_id), $fields)->assertRedirect($this->detail($item))->assertSessionHas('status', 'Your self-report was updated. Earlier revisions are retained.');
        $this->patch(route('items.update', $item->subject_id), $fields)->assertRedirect();
        $this->assertDatabaseCount('item_observations', 2);
        $this->assertDatabaseHas('item_observations', ['item_id' => $item->subject_id, 'revision' => 1]);
        $this->assertDatabaseHas('subjects', ['id' => $item->subject_id, 'version' => 2, 'public_code' => $item->subject->public_code]);
        $this->assertDatabaseHas('items', ['subject_id' => $item->subject_id, 'data_status' => 'YES', 'owner_user_id' => $this->owner->id]);
        $this->get($this->detail($item))->assertOk()->assertSee('Your self-report was updated.')->assertSee('Synthetic updated observation')->assertSee('Synthetic self-report: not tested.');
        $stale = array_merge($fields, ['idempotency_key' => Str::uuid()->toString()]);
        $this->patch(route('items.update', $item->subject_id), $stale)->assertStatus(409)->assertSee('device has changed');
        $changed = array_merge($fields, ['reason' => 'Different payload']);
        $this->patch(route('items.update', $item->subject_id), $changed)->assertStatus(409);
        foreach (['public_code', 'owner_user_id', 'identifier', 'identifier_kind', 'category', 'processing_state'] as $field) {
            $this->patch(route('items.update', $item->subject_id), array_merge($this->revision($item->refresh()->load('subject', 'category')), [$field => 'injected']))->assertSessionHasErrors($field);
        }
        DB::table('items')->where('subject_id', $item->subject_id)->update(['processing_state' => 'received']);
        $this->get(route('items.edit', $item->subject_id))->assertForbidden();
        $this->patch(route('items.update', $item->subject_id), $this->revision($item))->assertForbidden();
        $this->assertDatabaseCount('item_observations', 2);
    }

    public function test_database_rejects_overwritten_history_and_identity(): void
    {
        $item = $this->register(['identifier' => 'SYNTH-IMMUTABLE']);
        foreach ([fn () => DB::table('domain_events')->update(['type' => 'rewritten']), fn () => DB::table('item_observations')->delete(), fn () => DB::table('subjects')->update(['public_code' => 'replacement']), fn () => DB::table('items')->update(['owner_user_id' => User::factory()->create()->id]), fn () => DB::table('items')->update(['identifier_kind' => 'imei'])] as $mutation) {
            try {
                DB::transaction($mutation);
                $this->fail('Database allowed permanent history or identity mutation.');
            } catch (QueryException) {
                $this->assertDatabaseCount('domain_events', 1);
            }
        }
    }

    public function test_real_qr_decodes_to_authorized_opaque_lookup_and_reprint_keeps_identity(): void
    {
        $item = $this->register(['identifier' => 'SYNTH-PRIVATE', 'model' => 'Synthetic model']);
        $response = $this->get(route('items.qr', $item->subject_id))->assertOk()->assertHeader('Content-Type', 'image/png');
        // Scan every finder-pattern row; the decoder's fast path skips valid patterns for some opaque codes.
        $decoded = (new QrReader($response->getContent(), QrReader::SOURCE_TYPE_BLOB))->text(['TRY_HARDER' => true]);
        $this->assertSame(route('items.lookup', $item->subject->public_code), $decoded);
        foreach (['SYNTH-PRIVATE', $this->owner->email, 'Synthetic model'] as $private) {
            $this->assertStringNotContainsString($private, $decoded);
        }
        $this->get($decoded)->assertRedirect($this->detail($item));
        $this->get('/items/lookup?public_code='.$item->subject->public_code)->assertRedirect($this->detail($item));
        $this->get(route('items.label', $item->subject_id))->assertOk()->assertSee($item->subject->public_code)->assertSee('data-print-label', false);
        $download = $this->get(route('items.qr', ['item' => $item->subject_id, 'download' => 1]))->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="'.$item->subject->public_code.'.png"');
        $this->assertSame($decoded, (new QrReader($download->getContent(), QrReader::SOURCE_TYPE_BLOB))->text(['TRY_HARDER' => true]));
        $this->assertDatabaseCount('subjects', 1);
    }

    public function test_image_reencoding_removes_appended_metadata_and_private_photo_requires_scope(): void
    {
        $file = UploadedFile::fake()->image('device.png', 20, 20);
        $text = "GPSLatitude\0SYNTHETIC-GPS-PRIVATE-METADATA";
        $chunk = pack('N', strlen($text)).'tEXt'.$text.pack('N', crc32('tEXt'.$text));
        $original = file_get_contents($file->getPathname());
        file_put_contents($file->getPathname(), substr($original, 0, -12).$chunk.substr($original, -12));
        $item = $this->register(['photo' => $file]);
        $attachment = DB::table('attachments')->first();
        $stored = Storage::disk('device_photos')->get($attachment->storage_key);
        $this->assertStringNotContainsString('SYNTHETIC-GPS-PRIVATE-METADATA', $stored);
        $this->assertSame('image/png', (new \finfo(FILEINFO_MIME_TYPE))->buffer($stored));
        $this->assertSame(hash('sha256', $stored), $attachment->checksum);
        $this->assertSame('private', Storage::disk('device_photos')->getVisibility($attachment->storage_key));
        $this->get(route('items.photo', [$item->subject_id, $attachment->id]))->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/storage/'.$attachment->storage_key)->assertForbidden();
    }

    public function test_bad_oversize_svg_html_and_disguised_uploads_leave_no_records_or_files(): void
    {
        $bad = [UploadedFile::fake()->createWithContent('photo.png', '<?php echo "executable";'), UploadedFile::fake()->createWithContent('image.svg', '<svg></svg>'), UploadedFile::fake()->createWithContent('image.html', '<html>not an image</html>'), UploadedFile::fake()->create('large.jpg', 5121, 'image/jpeg')];
        $disguised = UploadedFile::fake()->image('payload.php');
        $bad[] = $disguised;
        $polyglot = UploadedFile::fake()->image('polyglot.jpg');
        file_put_contents($polyglot->getPathname(), '<?php echo "active content";', FILE_APPEND);
        $bad[] = $polyglot;
        foreach ($bad as $photo) {
            $this->post('/items', array_merge($this->fields(), ['photo' => $photo]))->assertSessionHasErrors('photo');
        }
        $this->assertDatabaseCount('items', 0);
        $this->assertCount(0, Storage::disk('device_photos')->allFiles());
    }

    public function test_storage_and_post_storage_transaction_failures_roll_back_and_clean_up(): void
    {
        $manager = Storage::getFacadeRoot();
        $disk = Storage::disk('device_photos');
        $failedDisk = \Mockery::mock(FilesystemAdapter::class);
        $failedDisk->shouldReceive('put')->once()->andReturnFalse();
        $failedDisk->shouldReceive('delete')->once()->andReturnTrue();
        Storage::shouldReceive('disk')->with('device_photos')->andReturn($failedDisk);
        $this->post('/items', array_merge($this->fields(), ['photo' => UploadedFile::fake()->image('device.png')]))->assertSessionHasErrors('photo');
        Storage::swap($manager);
        $this->assertDatabaseCount('items', 0);
        // Fail after the blob and attachment row exist, exercising the action's compensation.
        DB::listen(function ($query): void {
            if (str_starts_with($query->sql, 'insert into "evidence_links"')) {
                throw new \RuntimeException('Synthetic database failure after photo storage');
            }
        });
        try {
            app(RegisterDevice::class)->register($this->owner, $this->fields(), UploadedFile::fake()->image('device.png'));
            $this->fail('Expected synthetic failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic database failure after photo storage', $exception->getMessage());
        }
        foreach (['subjects', 'items', 'item_observations', 'domain_events', 'commands', 'custody_current', 'notification_outbox', 'attachments', 'evidence_links'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertCount(0, $disk->allFiles());
    }

    public function test_lists_paginate_owned_devices_with_useful_empty_state(): void
    {
        $this->get('/items')->assertOk()->assertSee('No devices registered yet')->assertSee('Register your first device');
        for ($i = 0; $i < 13; $i++) {
            app(RegisterDevice::class)->register($this->owner, array_merge($this->fields(), ['model' => 'Synthetic device '.$i]), null);
        }
        $response = $this->get('/items')->assertOk()->assertSee('Page 1 of 2');
        $this->assertSame(12, substr_count($response->getContent(), '<article'));
        $this->get('/items?page=2')->assertOk()->assertSee('Page 2 of 2');
    }

    public function test_safe_output_validation_associations_and_sensitive_input_not_flashed(): void
    {
        $item = $this->register(['model' => '<script>unsafe-model</script>', 'reason' => '<script>unsafe-notes</script>']);
        $this->get($this->detail($item))->assertOk()->assertSee('&lt;script&gt;unsafe-model&lt;/script&gt;', false)->assertDontSee('<script>unsafe-notes</script>', false);
        $fields = array_merge($this->fields(), ['identifier' => 'SYNTH-PRIVATE', 'model' => 'Safe retained model']);
        unset($fields['answers']['power']);
        $this->post('/items', $fields)->assertSessionHasErrors('answers.power')->assertSessionMissing('_old_input.identifier');
        $response = $this->withCookie(config('session.cookie'), session()->getId())->get('/items/create')->assertOk()->assertSee('Safe retained model')->assertSee('data-error-summary', false);
        $dom = new DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//select[@id="answers_power"][@aria-invalid="true"]')->length);
        $this->assertStringContainsString('answers_power-error', $xpath->query('//select[@id="answers_power"]')->item(0)->getAttribute('aria-describedby'));
        $this->assertGreaterThanOrEqual(1, $xpath->query('//a[@href="#answers_power"]')->length);
        $this->assertSame(1, $xpath->query('//*[@role="alert"]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="answers_power-error"]')->length);
    }

    public function test_suspended_or_stale_session_cannot_register(): void
    {
        $this->owner->forceFill(['status' => 'suspended'])->save();
        $this->post('/items', $this->fields())->assertRedirect(route('login'));
        $this->assertDatabaseCount('items', 0);
    }

    public function test_array_input_errors_render_safely_and_retry_keys_are_valid(): void
    {
        $this->post('/items', array_merge($this->fields(), ['idempotency_key' => ['invalid'], 'model' => ['invalid']]))->assertSessionHasErrors(['idempotency_key', 'model']);
        $this->withCookie(config('session.cookie'), session()->getId())->get('/items/create')->assertOk();
        $item = app(RegisterDevice::class)->register($this->owner, $this->fields(), null);
        $this->patch(route('items.update', $item->subject_id), array_merge($this->revision($item), ['expected_version' => ['invalid']]))->assertSessionHasErrors('expected_version');
        $this->withCookie(config('session.cookie'), session()->getId())->get(route('items.edit', $item->subject_id))->assertOk();
    }

    public function test_known_condition_and_hazard_answers_are_self_report_only(): void
    {
        $item = $this->register(['answers' => ['power' => 'PASS', 'display' => 'FAIL', 'input' => 'UNKNOWN', 'system' => 'PASS'], 'safety_concern' => 'YES', 'physical_damage' => 'YES', 'data_status' => 'NO']);
        $this->get($this->detail($item))->assertOk()->assertSee('Works / passed')->assertSee('Does not work / failed')->assertSee('Unknown / not tested')->assertSee('Safety information needs review')->assertDontSee('Approved for reuse');
        $this->assertSame('registered', $item->processing_state);
    }
}
