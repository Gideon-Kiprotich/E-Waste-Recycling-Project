<?php

namespace Tests\Feature;

use App\Support\Sanitization\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\BatchFixtures;
use Tests\TestCase;

class DismantlingMigrationTest extends TestCase
{
    use BatchFixtures;

    public function test_fresh_and_populated_upgrade_preserves_original_columns_and_canonical_assurance(): void
    {
        foreach ([false, true] as $populated) {
            $schema = 'batch_upgrade_'.str_replace('-', '', Str::uuid()->toString());
            $c = config('database.connections.pgsql');
            $this->assertSame('ewaste_testing', $c['database']);
            $c['search_path'] = $schema;
            config(['database.connections.batch_upgrade' => $c]);
            DB::setDefaultConnection('batch_upgrade');
            Schema::clearResolvedInstance('db.schema');
            try {
                DB::beginTransaction();
                DB::statement('CREATE SCHEMA "'.$schema.'"');
                $files = glob(database_path('migrations/*.php'));
                sort($files);
                foreach ($files as $file) {
                    if (basename($file) < '2026_10_18_000004_create_dismantling_batches.php') {
                        (require $file)->up();
                    }
                }
                if ($populated) {
                    $w = $this->inventoryWork();
                    $medium = $this->identifyMedium($w);
                    $this->inventoryRecord($w, 'attest');
                    $this->authorizeMedium($w, $medium);
                    $this->mediaSatisfied($this->mediumWork($w, $medium));
                    $returned = $this->mediaWork();
                    $this->mediaSatisfied($returned);
                    $this->reusePassed($returned);
                    $this->reuseRecord($returned, 'authorize');
                    $this->reuseRecord($returned, 'acknowledge', [], true);
                }
                $before = [];
                foreach (DB::select('SELECT tablename FROM pg_tables WHERE schemaname=? ORDER BY tablename', [$schema]) as $t) {
                    $cols = Schema::getColumnListing($t->tablename);
                    $before[$t->tablename] = ['columns' => $cols, 'rows' => DB::table($t->tablename)->select($cols)->get()->toJson()];
                }
                (require database_path('migrations/2026_10_18_000004_create_dismantling_batches.php'))->up();
                (require database_path('migrations/2026_10_18_000005_validate_batch_controls.php'))->up();
                (require database_path('migrations/2026_10_18_000006_require_complete_batch_measurements.php'))->up();
                (require database_path('migrations/2026_10_18_000007_preserve_linked_media_restrictions.php'))->up();
                foreach ($before as $table => $v) {
                    $this->assertSame($v['rows'], DB::table($table)->select($v['columns'])->get()->toJson(), $table);
                }
                if ($populated) {
                    $this->assertSame('satisfied', app(Context::class)->state($medium));
                }
                $journey = $this->batchWork(2);
                $this->batchRecord($journey);
                $this->batchRecord($journey, 'commit');
                $this->assertDatabaseCount('batch_members', 1);
                $this->assertDatabaseCount('batch_detachments', 2);
                $this->assertSame('pending_accounting', app(\App\Support\Batches\Context::class)->balance($journey['batch'])['status']);
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            } finally {
                while (DB::connection('batch_upgrade')->transactionLevel() > 0) {
                    DB::connection('batch_upgrade')->rollBack();
                }
                DB::setDefaultConnection('pgsql');
                Schema::clearResolvedInstance('db.schema');
                DB::purge('batch_upgrade');
            }
            $this->assertFalse(DB::table('pg_namespace')->where('nspname', $schema)->exists());
        }
    }
}
