<?php

namespace Tests\Feature;

use App\Support\Sanitization\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\SanitizationFixtures;
use Tests\TestCase;

class SanitizationMigrationTest extends TestCase
{
    use SanitizationFixtures;

    public function test_fresh_and_populated_upgrades_preserve_every_original_row_and_existing_media_identity(): void
    {
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1]);
        foreach ([false, true] as $populated) {
            $schema = 'sanitization_upgrade_'.str_replace('-', '', Str::uuid()->toString());
            $c = config('database.connections.pgsql');
            $this->assertSame('ewaste_testing', $c['database']);
            $c['search_path'] = $schema;
            config(['database.connections.sanitization_upgrade' => $c]);
            DB::setDefaultConnection('sanitization_upgrade');
            Schema::clearResolvedInstance('db.schema');
            DB::beginTransaction();
            try {
                DB::statement('CREATE SCHEMA "'.$schema.'"');
                $files = glob(database_path('migrations/*.php'));
                sort($files);
                foreach ($files as $f) {
                    if (basename($f) < '2026_10_16_000003_create_sanitization_records.php') {
                        (require $f)->up();
                    }
                }
                if ($populated) {
                    $w = $this->mediaWork('hdd');
                }
                $before = [];
                foreach (DB::select('SELECT tablename FROM pg_tables WHERE schemaname=? ORDER BY tablename', [$schema]) as $t) {
                    $before[$t->tablename] = DB::table($t->tablename)->get()->toJson();
                }
                (require database_path('migrations/2026_10_16_000003_create_sanitization_records.php'))->up();
                (require database_path('migrations/2026_10_16_000004_protect_sanitization_evidence.php'))->up();
                (require database_path('migrations/2026_10_16_000005_complete_sanitization_references.php'))->up();
                foreach ($before as $t => $rows) {
                    $this->assertSame($rows, DB::table($t)->get()->toJson(), $t);
                }
                $this->assertDatabaseCount('sanitization_policies', 1);
                $this->assertDatabaseCount('sanitization_records', 0);
                $this->assertDatabaseCount('sanitization_current', 0);
                if ($populated) {
                    $this->mediaSatisfied($w);
                    $this->assertSame('satisfied', app(Context::class)->state($w['ids'][0]));
                    foreach (['users', 'subjects', 'items', 'storage_assets', 'custody_current', 'first_receipts', 'handover_receipts', 'assessments', 'decisions', 'request_items'] as $t) {
                        $this->assertSame($before[$t], DB::table($t)->get()->toJson(), $t);
                    }
                } else {
                    $w = $this->mediaWork();
                    $this->mediaSatisfied($w);
                }
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                $this->assertDatabaseCount('sanitization_records', 3);
            } finally {
                while (DB::connection('sanitization_upgrade')->transactionLevel() > 0) {
                    DB::connection('sanitization_upgrade')->rollBack();
                }
                DB::setDefaultConnection('pgsql');
                Schema::clearResolvedInstance('db.schema');
                DB::purge('sanitization_upgrade');
            }
            $this->assertFalse(DB::table('pg_namespace')->where('nspname', $schema)->exists());
        }
    }
}
