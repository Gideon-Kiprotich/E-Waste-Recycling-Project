<?php

namespace Tests\Feature;

use App\Actions\RegisterDevice;
use App\Models\User;
use App\Support\Devices\Identifiers;
use App\Support\Devices\Questions;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class PartsMigrationTest extends TestCase
{
    private string $schema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1]);
        // TestCase guards before any access. This additional connection has the same guarded database.
        $configuration = config('database.connections.pgsql');
        $this->assertSame('ewaste_testing', $configuration['database']);
        $this->assertSame('pgsql', config('database.default'));
        $this->schema = 'parts_fixture_'.str_replace('-', '', Str::uuid()->toString());
        $configuration['search_path'] = $this->schema;
        config(['database.connections.parts_migration' => $configuration]);
        DB::setDefaultConnection('parts_migration');
        Schema::clearResolvedInstance('db.schema');
        DB::beginTransaction();
        DB::statement('CREATE SCHEMA "'.$this->schema.'"');
        $this->assertSame('ewaste_testing', DB::selectOne('SELECT current_database() AS name')->name);
        $this->assertSame($this->schema, DB::selectOne('SELECT current_schema() AS name')->name);
        // Run the unchanged historical migrations, including their mutable category import, in a private schema.
        $files = glob(database_path('migrations/*.php'));
        sort($files);
        foreach ($files as $file) {
            if (basename($file) < '2026_10_06_000001_create_standalone_parts_intake.php') {
                (require $file)->up();
            }
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->schema)) {
            while (DB::connection('parts_migration')->transactionLevel() > 0) {
                DB::connection('parts_migration')->rollBack();
            }
            DB::setDefaultConnection('pgsql');
            Schema::clearResolvedInstance('db.schema');
            DB::purge('parts_migration');
            $this->assertFalse(DB::table('pg_namespace')->where('nspname', $this->schema)->exists());
        }
        parent::tearDown();
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_06_000001_create_standalone_parts_intake.php');
    }

    private function legacyFields(): array
    {
        return ['category' => 'laptop', 'idempotency_key' => Str::uuid()->toString(), 'data_status' => 'UNKNOWN', 'safety_concern' => 'UNKNOWN', 'physical_damage' => 'UNKNOWN', 'reason' => 'Legacy fixture.', 'answers' => ['power' => 'UNKNOWN', 'display' => 'UNKNOWN', 'input' => 'UNKNOWN', 'system' => 'UNKNOWN'], 'identifier' => 'SYNTH-LEGACY'];
    }

    private function legacyRegistration(User $owner, array $fields): string
    {
        // Frozen pre-parts canonical bytes: no identifier_kind default, intake class or derived metadata.
        $canonical = '{"answers":{"display":"UNKNOWN","input":"UNKNOWN","power":"UNKNOWN","system":"UNKNOWN"},"category":"laptop","data_status":"UNKNOWN","identifier":"SYNTH-LEGACY","physical_damage":"UNKNOWN","reason":"Legacy fixture.","safety_concern":"UNKNOWN","photo_checksum":null}';
        $id = Str::uuid()->toString();
        $command = Str::uuid()->toString();
        $observation = Str::uuid()->toString();
        $event = Str::uuid()->toString();
        $party = Str::uuid()->toString();
        DB::table('subjects')->insert(['id' => $id, 'public_code' => 'EWC-'.strtoupper(bin2hex(random_bytes(16))), 'kind' => 'item', 'version' => 1, 'created_at' => now()]);
        DB::table('items')->insert(['subject_id' => $id, 'owner_user_id' => $owner->id, 'category_id' => DB::table('categories')->where('code', 'laptop')->value('id'), 'identifier_ciphertext' => Crypt::encryptString($fields['identifier']), 'identifier_hmac' => Identifiers::digest('SYNTHLEGACY'), 'identifier_key_version' => Identifiers::KEY_VERSION, 'identifier_kind' => 'serial', 'data_status' => 'UNKNOWN']);
        DB::table('commands')->insert(['id' => $command, 'actor_id' => $owner->id, 'action' => 'register_item', 'idempotency_key' => $fields['idempotency_key'], 'payload_hash' => Identifiers::digest($canonical, 'command'), 'status' => 'completed', 'safe_response' => json_encode(['subject_id' => $id])]);
        DB::table('item_observations')->insert(['id' => $observation, 'item_id' => $id, 'source' => 'self_report', 'observer_id' => $owner->id, 'answers' => json_encode(array_intersect_key($fields, array_flip(['answers', 'data_status', 'safety_concern', 'physical_damage', 'reason']))), 'question_version' => Questions::VERSION, 'revision' => 1, 'observed_at' => now()]);
        DB::table('domain_events')->insert(['id' => $event, 'subject_id' => $id, 'aggregate_kind' => 'item', 'aggregate_id' => $id, 'aggregate_version' => 1, 'actor_id' => $owner->id, 'type' => 'item_registered', 'occurred_at' => now(), 'recorded_at' => now(), 'command_id' => $command, 'safe_payload' => json_encode(['observation_id' => $observation, 'question_version' => Questions::VERSION])]);
        DB::table('parties')->insert(['id' => $party, 'kind' => 'user', 'user_id' => $owner->id]);
        DB::table('custody_current')->insert(['subject_id' => $id, 'party_id' => $party, 'last_event_id' => $event, 'version' => 1]);
        DB::table('notification_outbox')->insert(['id' => Str::uuid()->toString(), 'event_id' => $event, 'recipient_id' => $owner->id, 'channel' => 'in_app', 'state' => 'pending']);

        return $id;
    }

    private function snapshot(): array
    {
        $state = [];
        foreach (['users', 'memberships', 'categories', 'subjects', 'items', 'commands', 'item_observations', 'domain_events', 'parties', 'custody_current', 'notification_outbox'] as $table) {
            $state[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
            if ($table === 'categories') {
                $state[$table] = array_values(array_filter($state[$table], fn ($row) => $row['code'] !== 'standalone_part'));
            }
            if ($table === 'items') {
                foreach ($state[$table] as &$row) {
                    unset($row['intake_class']);
                }
            }
        }

        return $state;
    }

    private function legacyRevision(User $owner, string $id, array $fields): array
    {
        unset($fields['category'], $fields['identifier']);
        $fields['idempotency_key'] = Str::uuid()->toString();
        $fields['expected_version'] = 1;
        $fields['reason'] = 'Legacy revision.';
        $canonical = '{"answers":{"display":"UNKNOWN","input":"UNKNOWN","power":"UNKNOWN","system":"UNKNOWN"},"data_status":"UNKNOWN","expected_version":1,"physical_damage":"UNKNOWN","reason":"Legacy revision.","safety_concern":"UNKNOWN","photo_checksum":null}';
        $command = Str::uuid()->toString();
        $observation = Str::uuid()->toString();
        $event = Str::uuid()->toString();
        DB::table('subjects')->where('id', $id)->update(['version' => 2]);
        DB::table('commands')->insert(['id' => $command, 'actor_id' => $owner->id, 'action' => 'revise_item:'.$id, 'idempotency_key' => $fields['idempotency_key'], 'payload_hash' => Identifiers::digest($canonical, 'command'), 'status' => 'completed', 'safe_response' => json_encode(['subject_id' => $id])]);
        DB::table('item_observations')->insert(['id' => $observation, 'item_id' => $id, 'source' => 'self_report', 'observer_id' => $owner->id, 'answers' => json_encode(array_intersect_key($fields, array_flip(['answers', 'data_status', 'safety_concern', 'physical_damage', 'reason']))), 'question_version' => Questions::VERSION, 'revision' => 2, 'observed_at' => now()]);
        DB::table('domain_events')->insert(['id' => $event, 'subject_id' => $id, 'aggregate_kind' => 'item', 'aggregate_id' => $id, 'aggregate_version' => 2, 'actor_id' => $owner->id, 'type' => 'self_report_revised', 'occurred_at' => now(), 'recorded_at' => now(), 'command_id' => $command, 'safe_payload' => json_encode(['observation_id' => $observation, 'question_version' => Questions::VERSION])]);
        DB::table('notification_outbox')->insert(['id' => Str::uuid()->toString(), 'event_id' => $event, 'recipient_id' => $owner->id, 'channel' => 'in_app', 'state' => 'pending']);

        return $fields;
    }

    public function test_populated_upgrade_preserves_accounts_identity_history_and_legacy_registration_retry(): void
    {
        // Match a real pre-extension installation's six-category state.
        DB::table('categories')->where('code', 'standalone_part')->delete();
        $owner = User::factory()->create();
        $fields = $this->legacyFields();
        $id = $this->legacyRegistration($owner, $fields);
        $legacyRevision = $this->legacyRevision($owner, $id, $fields);
        $before = $this->snapshot();
        DB::transaction(fn () => $this->migration()->up());
        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseHas('items', ['subject_id' => $id, 'intake_class' => 'whole_device']);
        $this->assertDatabaseCount('storage_assets', 0);
        $result = app(RegisterDevice::class)->register($owner, $fields, null);
        $this->assertSame($id, $result->subject_id);
        $this->assertSame($before, $this->snapshot());
        $this->actingAs($owner)->withSession(['auth_session_version' => $owner->session_version]);
        $this->post('/items', $fields)->assertRedirect('/items/'.$id)->assertSessionHasNoErrors();
        $this->patch('/items/'.$id.'/observations', $legacyRevision)->assertRedirect('/items/'.$id)->assertSessionHasNoErrors();
        $this->assertSame($before, $this->snapshot());
        // Current views require the later ledgers; retain the isolated Phase 2A preservation assertions above.
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $later = glob(database_path('migrations/*.php'));
        sort($later);
        foreach ($later as $file) {
            if (basename($file) > '2026_10_06_000001_create_standalone_parts_intake.php') {
                (require $file)->up();
            }
        }
        $this->get('/items/'.$id)->assertOk()->assertSee(Questions::VERSION)->assertSee('Power / self-test');
        $revision = $fields;
        unset($revision['category'], $revision['identifier']);
        $revision['idempotency_key'] = Str::uuid()->toString();
        $revision['expected_version'] = 2;
        $this->patch('/items/'.$id.'/observations', $revision)->assertRedirect();
        $this->patch('/items/'.$id.'/observations', $revision)->assertRedirect();
        $this->assertDatabaseCount('item_observations', 3);
        DB::statement('SET CONSTRAINTS complete_item_storage, complete_storage_item IMMEDIATE');
    }

    public function test_fresh_install_corrects_only_the_known_seed_and_enforces_matching_same_id_facet(): void
    {
        $category = DB::table('categories')->where('code', 'standalone_part')->first();
        $this->assertSame(Questions::VERSION, $category->question_set_version);
        DB::transaction(fn () => $this->migration()->up());
        $this->assertDatabaseHas('categories', ['id' => $category->id, 'question_set_version' => Questions::PARTS_VERSION]);
        $this->assertDatabaseCount('categories', 7);
        $this->assertDatabaseCount('items', 0);
        $owner = User::factory()->create();
        $fields = ['category' => 'standalone_part', 'subtype' => 'ssd', 'idempotency_key' => Str::uuid()->toString(), 'physical_damage' => 'UNKNOWN', 'safety_concern' => 'UNKNOWN', 'reason' => 'Fresh fixture.', 'answers' => ['previous_operation' => 'UNKNOWN', 'past_heat_issue' => 'UNKNOWN', 'connector_damage' => 'UNKNOWN']];
        $item = app(RegisterDevice::class)->register($owner, $fields, null);
        DB::statement('SET CONSTRAINTS complete_item_storage, complete_storage_item IMMEDIATE');
        $this->assertDatabaseHas('storage_assets', ['subject_id' => $item->subject_id, 'media_kind' => 'ssd']);
        $this->assertDatabaseCount('subjects', 1);
        $this->assertDatabaseCount('items', 1);
        $this->assertDatabaseCount('storage_assets', 1);
        $this->assertSame('YES', $item->data_status);
    }

    public function test_unexpected_seed_or_identity_protection_aborts_before_schema_or_data_changes(): void
    {
        $category = DB::table('categories')->where('code', 'standalone_part')->first();
        foreach (['seed', 'guard'] as $case) {
            if ($case === 'seed') {
                DB::table('categories')->where('id', $category->id)->update(['label' => 'Unexpected configuration']);
            } else {
                DB::table('categories')->where('id', $category->id)->update(['label' => $category->label]);
                DB::statement('ALTER TABLE items DISABLE TRIGGER permanent_item_identity');
            }
            $before = $this->snapshot();
            try {
                DB::transaction(fn () => $this->migration()->up());
                $this->fail('Unexpected state must block migration.');
            } catch (RuntimeException $exception) {
                $this->assertStringStartsWith('Parts migration blocked:', $exception->getMessage());
            }
            $this->assertFalse(Schema::hasColumn('items', 'intake_class'));
            $this->assertFalse(Schema::hasTable('storage_assets'));
            $this->assertSame($before, $this->snapshot());
            $this->assertSame(Questions::VERSION, DB::table('categories')->where('id', $category->id)->value('question_set_version'));
        }
    }

    public function test_forward_migration_refuses_destructive_rollback(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('reviewed forward migration');
        $this->migration()->down();
    }

    public function test_unexpected_legacy_item_shape_stops_migration_without_guessing(): void
    {
        $owner = User::factory()->create();
        $id = $this->legacyRegistration($owner, $this->legacyFields());
        // Simulate historical corruption only within this private, fully rolled-back fixture schema.
        DB::statement('ALTER TABLE items DISABLE TRIGGER permanent_item_identity');
        DB::table('items')->where('subject_id', $id)->update(['subtype' => 'keyboard']);
        DB::statement('ALTER TABLE items ENABLE TRIGGER permanent_item_identity');
        $before = $this->snapshot();
        try {
            DB::transaction(fn () => $this->migration()->up());
            $this->fail('Unexpected existing data requires review, not an inferred classification.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('unexpected existing item classification', $exception->getMessage());
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertFalse(Schema::hasColumn('items', 'intake_class'));
        $this->assertFalse(Schema::hasTable('storage_assets'));
    }
}
