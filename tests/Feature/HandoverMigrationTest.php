<?php

namespace Tests\Feature;

use App\Actions\CustodyHandovers;
use App\Actions\RecordVisit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\HandoverFixtures;
use Tests\TestCase;

class HandoverMigrationTest extends TestCase
{
    use HandoverFixtures;

    public function test_fresh_and_populated_upgrades_preserve_existing_first_receipts_and_allow_a_subsequent_chain(): void
    {
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1]);
        foreach ([false, true] as $populated) {
            $schema = 'handover_upgrade_'.str_replace('-', '', Str::uuid()->toString());
            $c = config('database.connections.pgsql');
            $this->assertSame('ewaste_testing', $c['database']);
            $c['search_path'] = $schema;
            config(['database.connections.handover_upgrade' => $c]);
            DB::setDefaultConnection('handover_upgrade');
            Schema::clearResolvedInstance('db.schema');
            DB::beginTransaction();
            try {
                DB::statement('CREATE SCHEMA "'.$schema.'"');
                $files = glob(database_path('migrations/*.php'));
                sort($files);
                foreach ($files as $f) {
                    if (basename($f) < '2026_10_15_000005_create_custody_handovers.php') {
                        (require $f)->up();
                    }
                }
                if ($populated) {
                    [$admin,$owner,$collector,$route,$ordered] = $this->pickupWork();
                    $request = DB::table('assignments')->where('id', $ordered[0])->value('request_id');
                    app(RecordVisit::class)->record($collector, $request, $this->visitFields($request));
                    $point = DB::table('places')->where('code', 'synthetic-study-all-ict')->value('id');
                    $pointActor = $this->pointStaff($admin, $point);
                }
                $this->handoverBoundary();
                $before = [];
                foreach (DB::select('SELECT tablename FROM pg_tables WHERE schemaname=? ORDER BY tablename', [$schema]) as $t) {
                    $before[$t->tablename] = DB::table($t->tablename)->get()->toJson();
                }
                (require database_path('migrations/2026_10_15_000005_create_custody_handovers.php'))->up();
                (require database_path('migrations/2026_10_15_000006_preserve_handover_execution.php'))->up();
                (require database_path('migrations/2026_10_15_000007_retain_current_custody.php'))->up();
                foreach ($before as $t => $rows) {
                    $this->assertSame($rows, DB::table($t)->get()->toJson(), $t);
                }
                $this->assertTrue(DB::selectOne("SELECT indisunique AND indisvalid AND indisready AS ok FROM pg_index WHERE indexrelid='handover_subject_active_unique'::regclass")->ok);
                if ($populated) {
                    $receiver = $this->pointStaff($admin, $this->facility($point));
                    $id = app(CustodyHandovers::class)->initiate($collector, $this->initiateFields($collector, $receiver));
                    $this->handoverBoundary();
                    app(CustodyHandovers::class)->change($receiver, $id, 'acknowledged', $this->acknowledgeFields($id));
                    $this->handoverBoundary();
                    $this->assertSame($before['first_receipts'], DB::table('first_receipts')->get()->toJson());
                    $this->assertDatabaseCount('handover_receipts', 1);
                }
                $this->handoverBoundary();
            } finally {
                while (DB::connection('handover_upgrade')->transactionLevel() > 0) {
                    DB::connection('handover_upgrade')->rollBack();
                }
                DB::setDefaultConnection('pgsql');
                Schema::clearResolvedInstance('db.schema');
                DB::purge('handover_upgrade');
            }
            $this->assertFalse(DB::table('pg_namespace')->where('nspname', $schema)->exists());
        }
    }
}
