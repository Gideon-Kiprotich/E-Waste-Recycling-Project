<?php

namespace Tests\Feature;

use App\Actions\AllocateCollector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\AssignmentFixtures;
use Tests\TestCase;

class RoutingMigrationTest extends TestCase
{
    use AssignmentFixtures;

    public function test_guarded_fresh_install_and_populated_upgrade_preserve_all_original_rows(): void
    {
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1]);
        foreach ([false, true] as $populated) {
            $schema = 'routing_upgrade_'.str_replace('-', '', Str::uuid()->toString());
            $c = config('database.connections.pgsql');
            $this->assertSame('ewaste_testing', $c['database']);
            $c['search_path'] = $schema;
            config(['database.connections.routing_upgrade' => $c]);
            DB::setDefaultConnection('routing_upgrade');
            Schema::clearResolvedInstance('db.schema');
            DB::beginTransaction();
            try {
                DB::statement('CREATE SCHEMA "'.$schema.'"');
                $files = glob(database_path('migrations/*.php'));
                sort($files);
                foreach ($files as $file) {
                    if (basename($file) < '2026_10_15_000001_create_route_planning.php') {
                        (require $file)->up();
                    }
                }
                if ($populated) {
                    [$admin,$owner,$collector,$place] = $this->allocationSetup();
                    $request = $this->readyRequest($admin, $owner);
                    $assignment = app(AllocateCollector::class)->allocate($admin, $request, $this->offerFields($request, $collector, $place));
                    app(AllocateCollector::class)->respond($collector, $assignment, $this->responseFields($assignment));
                }
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                $before = [];
                foreach (DB::select('SELECT tablename FROM pg_tables WHERE schemaname=? ORDER BY tablename', [$schema]) as $t) {
                    $before[$t->tablename] = DB::table($t->tablename)->get()->toJson();
                }
                (require database_path('migrations/2026_10_15_000001_create_route_planning.php'))->up();
                foreach ($before as $t => $rows) {
                    $this->assertSame($rows, DB::table($t)->get()->toJson(), $t);
                }
                foreach (['vehicles', 'route_runs', 'routes', 'route_stops', 'route_claims', 'route_approvals'] as $t) {
                    $this->assertDatabaseCount($t, 0);
                }
                $this->assertTrue(DB::selectOne("SELECT indisunique AND indisvalid AND indisready AS ok FROM pg_index WHERE indexrelid='route_claims_pkey'::regclass")->ok);
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            } finally {
                while (DB::connection('routing_upgrade')->transactionLevel() > 0) {
                    DB::connection('routing_upgrade')->rollBack();
                }DB::setDefaultConnection('pgsql');
                Schema::clearResolvedInstance('db.schema');
                DB::purge('routing_upgrade');
            }
            $this->assertFalse(DB::table('pg_namespace')->where('nspname', $schema)->exists());
        }
    }
}
