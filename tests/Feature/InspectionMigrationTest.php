<?php

namespace Tests\Feature;

use App\Actions\CustodyHandovers;
use App\Actions\InspectItems;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\InspectionFixtures;
use Tests\TestCase;

class InspectionMigrationTest extends TestCase
{
    use InspectionFixtures;

    public function test_fresh_and_populated_forward_upgrades_preserve_rows_and_acknowledged_custody(): void
    {
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1]);
        foreach ([false, true] as $populated) {
            $schema = 'inspection_upgrade_'.str_replace('-', '', Str::uuid()->toString());
            $c = config('database.connections.pgsql');
            $this->assertSame('ewaste_testing', $c['database']);
            $c['search_path'] = $schema;
            config(['database.connections.inspection_upgrade' => $c]);
            DB::setDefaultConnection('inspection_upgrade');
            Schema::clearResolvedInstance('db.schema');
            DB::beginTransaction();
            try {
                DB::statement('CREATE SCHEMA "'.$schema.'"');
                $files = glob(database_path('migrations/*.php'));
                sort($files);
                foreach ($files as $f) {
                    if (basename($f) < '2026_10_16_000001_create_inspection_decisions.php') {
                        (require $f)->up();
                    }
                }
                if ($populated) {
                    $w = $this->handoverWork();
                    $id = $this->initiateHandover($w);
                    app(CustodyHandovers::class)->change($w['receiver'], $id, 'acknowledged', $this->acknowledgeFields($id));
                    $this->handoverBoundary();
                }
                $before = [];
                foreach (DB::select('SELECT tablename FROM pg_tables WHERE schemaname=? ORDER BY tablename', [$schema]) as $t) {
                    $before[$t->tablename] = DB::table($t->tablename)->get()->toJson();
                }
                (require database_path('migrations/2026_10_16_000001_create_inspection_decisions.php'))->up();
                (require database_path('migrations/2026_10_16_000002_enforce_inspection_restrictions.php'))->up();
                foreach ($before as $t => $rows) {
                    $this->assertSame($rows, DB::table($t)->get()->toJson(), $t);
                }
                $this->assertDatabaseCount('rule_sets', 2);
                $this->assertDatabaseCount('assessments', 0);
                if ($populated) {
                    $custody = DB::table('custody_current')->get()->toJson();
                    $id = app(InspectItems::class)->inspect($w['receiver'], $w['ids'][0], $this->inspectionFields($w['ids'][0]));
                    $this->handoverBoundary();
                    $this->assertSame('refurbish', DB::table('assessments')->where('id', $id)->value('recommendation'));
                    $this->assertSame($custody, DB::table('custody_current')->get()->toJson());
                    foreach (['first_receipts', 'handover_receipts', 'storage_assets'] as $t) {
                        $this->assertSame($before[$t], DB::table($t)->get()->toJson());
                    }
                }
            } finally {
                while (DB::connection('inspection_upgrade')->transactionLevel() > 0) {
                    DB::connection('inspection_upgrade')->rollBack();
                }
                DB::setDefaultConnection('pgsql');
                Schema::clearResolvedInstance('db.schema');
                DB::purge('inspection_upgrade');
            }
            $this->assertFalse(DB::table('pg_namespace')->where('nspname', $schema)->exists());
        }
    }
}
