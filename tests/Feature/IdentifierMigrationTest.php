<?php

namespace Tests\Feature;

use App\Actions\RegisterDevice;
use App\Models\User;
use App\Support\Devices\Identifiers;
use App\Support\Devices\Questions;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class IdentifierMigrationTest extends TestCase
{
    use DatabaseTransactions;

    private function legacySchemaWithIdentifier(): array
    {
        $user = User::factory()->create();
        $item = app(RegisterDevice::class)->register($user, [
            'category' => 'laptop', 'idempotency_key' => Str::uuid()->toString(), 'identifier' => 'SYNTH-LEGACY',
            'data_status' => 'UNKNOWN', 'safety_concern' => 'UNKNOWN', 'physical_damage' => 'UNKNOWN',
            'reason' => 'Synthetic migration fixture.', 'answers' => array_fill_keys(array_keys(Questions::tests('laptop', null)), 'UNKNOWN'),
        ], null);
        // All DDL and synthetic rows are inside the test transaction; the live schema is restored on rollback.
        DB::statement('DROP TRIGGER permanent_identifier_kind ON items');
        DB::statement('DROP FUNCTION protect_device_identifier_kind()');
        DB::statement('ALTER TABLE items DROP CONSTRAINT items_identifier_kind_check');
        DB::statement('ALTER TABLE items DROP COLUMN identifier_kind');
        DB::statement('DROP INDEX '.Identifiers::UNIQUE_INDEX);
        DB::statement('CREATE INDEX items_identifier_hmac_index ON items(identifier_hmac) WHERE identifier_hmac IS NOT NULL');

        return (array) DB::table('items')->where('subject_id', $item->subject_id)->first();
    }

    public function test_duplicate_preflight_fails_before_schema_or_identifier_changes(): void
    {
        $row = $this->legacySchemaWithIdentifier();
        $row['subject_id'] = Str::uuid()->toString();
        DB::table('subjects')->insert(['id' => $row['subject_id'], 'public_code' => 'EWC-'.strtoupper(bin2hex(random_bytes(16))), 'kind' => 'item', 'version' => 1, 'created_at' => now()]);
        DB::table('items')->insert($row);
        $before = DB::table('items')->orderBy('subject_id')->get()->toJson();
        $migration = require database_path('migrations/2026_10_05_000001_enforce_unique_device_identifiers.php');
        try {
            DB::transaction(fn () => $migration->up());
            $this->fail('A reviewed correction must precede this migration.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Identifier migration blocked: 1 duplicate comparison group(s), 2 record(s). A reviewed correction is required; no identifiers or records were changed.', $exception->getMessage());
            $this->assertStringNotContainsString($row['identifier_hmac'], $exception->getMessage());
            $this->assertStringNotContainsString($row['identifier_ciphertext'], $exception->getMessage());
        }
        $this->assertFalse(Schema::hasColumn('items', 'identifier_kind'));
        $this->assertSame($before, DB::table('items')->orderBy('subject_id')->get()->toJson());
        $this->assertNull(DB::selectOne('SELECT to_regclass(?) AS name', [Identifiers::UNIQUE_INDEX])->name);
    }

    public function test_nonconflicting_legacy_rows_keep_ciphertext_hash_and_unspecified_type(): void
    {
        $row = $this->legacySchemaWithIdentifier();
        $migration = require database_path('migrations/2026_10_05_000001_enforce_unique_device_identifiers.php');
        DB::transaction(fn () => $migration->up());
        $after = (array) DB::table('items')->where('subject_id', $row['subject_id'])->first();
        $this->assertSame('legacy_unspecified', $after['identifier_kind']);
        unset($after['identifier_kind']);
        $this->assertSame($row, $after);
        $this->assertNotNull(DB::selectOne('SELECT to_regclass(?) AS name', [Identifiers::UNIQUE_INDEX])->name);
    }
}
