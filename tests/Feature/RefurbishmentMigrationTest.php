<?php

namespace Tests\Feature;

use App\Actions\RecordSanitization;
use App\Support\Sanitization\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\RefurbishmentFixtures;
use Tests\TestCase;

class RefurbishmentMigrationTest extends TestCase
{
    use RefurbishmentFixtures;

    public function test_fresh_and_populated_upgrade_preserves_custody_assurance_and_commands(): void
    {
        foreach ([false, true] as $populated) {
            $schema = 'reuse_upgrade_'.str_replace('-', '', Str::uuid()->toString());
            $c = config('database.connections.pgsql');
            $this->assertSame('ewaste_testing', $c['database']);
            $c['search_path'] = $schema;
            config(['database.connections.reuse_upgrade' => $c]);
            DB::setDefaultConnection('reuse_upgrade');
            Schema::clearResolvedInstance('db.schema');
            try {
                DB::beginTransaction();
                DB::statement('CREATE SCHEMA "'.$schema.'"');
                $files = glob(database_path('migrations/*.php'));
                sort($files);
                foreach ($files as $file) {
                    if (basename($file) < '2026_10_18_000001_create_refurbishment_release.php') {
                        (require $file)->up();
                    }
                }
                if ($populated) {
                    $w = $this->mediaWork();
                    $fields = $this->mediaFields($w);
                    $original = $this->mediaRecord($w, 'attempt', $fields);
                    $this->mediaRecord($w, 'verification');
                    $this->mediaRecord($w, 'validation');
                    $parent = $this->inventoryWork();
                    $medium = $this->identifyMedium($parent);
                    $this->inventoryRecord($parent, 'attest');
                    $this->authorizeMedium($parent, $medium);
                }
                $before = [];
                foreach (DB::select('SELECT tablename FROM pg_tables WHERE schemaname=? ORDER BY tablename', [$schema]) as $t) {
                    $before[$t->tablename] = DB::table($t->tablename)->get()->toJson();
                }
                (require database_path('migrations/2026_10_18_000001_create_refurbishment_release.php'))->up();
                (require database_path('migrations/2026_10_18_000002_validate_reuse_controls.php'))->up();
                (require database_path('migrations/2026_10_18_000003_freeze_reuse_recipient_access.php'))->up();
                foreach ($before as $table => $rows) {
                    $this->assertSame($rows, DB::table($table)->get()->toJson(), $table);
                }
                $this->assertDatabaseCount('refurbishment_records', 0);
                $this->assertDatabaseCount('reuse_return_items', 0);
                $this->assertDatabaseCount('refurbishment_profiles', 1);
                if ($populated) {
                    $this->assertSame($original, app(RecordSanitization::class)->record($w['receiver'], $w['ids'][0], 'attempt', $fields));
                    $this->assertSame('satisfied', app(Context::class)->state($w['ids'][0]));
                }
                $journey = $populated ? $w : $this->mediaWork();
                if (! $populated) {
                    $this->mediaSatisfied($journey);
                }
                $this->reusePassed($journey);
                $this->reuseRecord($journey, 'authorize');
                $this->reuseRecord($journey, 'acknowledge', [], true);
                $this->assertDatabaseCount('reuse_return_items', 1);
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            } finally {
                while (DB::connection('reuse_upgrade')->transactionLevel() > 0) {
                    DB::connection('reuse_upgrade')->rollBack();
                }
                DB::setDefaultConnection('pgsql');
                Schema::clearResolvedInstance('db.schema');
                DB::purge('reuse_upgrade');
            }
            $this->assertFalse(DB::table('pg_namespace')->where('nspname', $schema)->exists());
        }
    }
}
