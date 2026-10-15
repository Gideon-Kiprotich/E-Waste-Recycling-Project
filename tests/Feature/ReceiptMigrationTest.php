<?php

namespace Tests\Feature;

use App\Actions\PlanRoutes;
use App\Support\Routing\Execution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\RoutingFixtures;
use Tests\TestCase;

class ReceiptMigrationTest extends TestCase
{
    use RoutingFixtures;

    public function test_guarded_fresh_install_and_populated_upgrade_preserve_all_original_rows(): void
    {
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1]);
        foreach ([false, true] as $populated) {
            $schema = 'receipt_upgrade_'.str_replace('-', '', Str::uuid()->toString());
            $c = config('database.connections.pgsql');
            $this->assertSame('ewaste_testing', $c['database']);
            $c['search_path'] = $schema;
            config(['database.connections.receipt_upgrade' => $c]);
            DB::setDefaultConnection('receipt_upgrade');
            Schema::clearResolvedInstance('db.schema');
            DB::beginTransaction();
            try {
                DB::statement('CREATE SCHEMA "'.$schema.'"');
                $files = glob(database_path('migrations/*.php'));
                sort($files);
                foreach ($files as $file) {
                    if (basename($file) < '2026_10_15_000002_create_physical_receipts.php') {
                        (require $file)->up();
                    }
                }
                if ($populated) {
                    [$admin,$owner,$collector,$place,$depot,$vehicle] = $this->routingSetup();
                    $assignment = $this->accepted($admin, $owner, $collector, $place);
                    $run = app(PlanRoutes::class)->create($admin, $this->routeFields($depot, $vehicle, [$assignment]));
                    app(PlanRoutes::class)->approve($admin, $run, $this->approvalFields());
                }
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                $before = [];
                foreach (DB::select('SELECT tablename FROM pg_tables WHERE schemaname=? ORDER BY tablename', [$schema]) as $t) {
                    $before[$t->tablename] = ['columns' => array_keys((array) DB::table($t->tablename)->first()), 'rows' => DB::table($t->tablename)->get()->toJson()];
                }
                (require database_path('migrations/2026_10_15_000002_create_physical_receipts.php'))->up();
                (require database_path('migrations/2026_10_15_000003_enforce_receipt_boundaries.php'))->up();
                (require database_path('migrations/2026_10_15_000004_protect_receipt_projections.php'))->up();
                foreach ($before as $t => $rows) {
                    $this->assertSame($rows['rows'], DB::table($t)->get($rows['columns'] ?: ['*'])->toJson(), $t);
                }
                foreach (['receipt_visits', 'receipt_outcomes', 'first_receipts', 'route_consumptions', 'receipt_route_changes', 'route_finishes'] as $t) {
                    $this->assertDatabaseCount($t, 0);
                }
                $this->assertTrue(DB::selectOne("SELECT indisunique AND indisvalid AND indisready AS ok FROM pg_index WHERE indexrelid='first_receipts_pkey'::regclass")->ok);
                if ($populated) {
                    $this->assertTrue(app(Execution::class)->current($run));
                    DB::table('items')->update(['data_status' => DB::raw('data_status')]);
                    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                }
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            } finally {
                while (DB::connection('receipt_upgrade')->transactionLevel() > 0) {
                    DB::connection('receipt_upgrade')->rollBack();
                }DB::setDefaultConnection('pgsql');
                Schema::clearResolvedInstance('db.schema');
                DB::purge('receipt_upgrade');
            }
            $this->assertFalse(DB::table('pg_namespace')->where('nspname', $schema)->exists());
        }
    }
}
