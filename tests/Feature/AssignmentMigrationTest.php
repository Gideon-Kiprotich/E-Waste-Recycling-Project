<?php

namespace Tests\Feature;

use App\Actions\CollectionRequests;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\AssignmentFixtures;
use Tests\TestCase;

class AssignmentMigrationTest extends TestCase
{
    use AssignmentFixtures;

    public function test_populated_upgrade_and_fresh_install_preserve_original_data_and_create_no_guessed_scope(): void
    {
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1]);
        foreach ([false, true] as $populated) {
            $schema = 'assignment_upgrade_'.str_replace('-', '', Str::uuid()->toString());
            $c = config('database.connections.pgsql');
            $this->assertSame('ewaste_testing', $c['database']);
            $c['search_path'] = $schema;
            config(['database.connections.assignment_upgrade' => $c]);
            DB::setDefaultConnection('assignment_upgrade');
            Schema::clearResolvedInstance('db.schema');
            DB::beginTransaction();
            try {
                DB::statement('CREATE SCHEMA "'.$schema.'"');
                $files = glob(database_path('migrations/*.php'));
                sort($files);
                foreach ($files as $file) {
                    if (basename($file) < '2026_10_09_000002_create_collector_assignments.php') {
                        (require $file)->up();
                    }
                }
                if ($populated) {
                    $this->directory();
                    $owner = User::factory()->create();
                    $legacy = User::factory()->create();
                    $legacy->membership()->update(['role' => 'collector']);
                    $item = $this->device($owner, 'standalone_part', 'ssd');
                    app(CollectionRequests::class)->submit($owner, $this->requestFields([$item->subject_id]));
                }
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                $before = [];
                foreach (DB::select('SELECT tablename FROM pg_tables WHERE schemaname=? ORDER BY tablename', [$schema]) as $t) {
                    $columns = Schema::getColumnListing($t->tablename);
                    $before[$t->tablename] = [$columns, DB::table($t->tablename)->select($columns)->get()->toJson()];
                }
                (require database_path('migrations/2026_10_09_000002_create_collector_assignments.php'))->up();
                foreach ($before as $table => [$columns, $rows]) {
                    $this->assertSame($rows, DB::table($table)->select($columns)->get()->toJson(), $table);
                }
                $this->assertSame(0, DB::table('memberships')->whereNotNull('organization_id')->count());
                $this->assertDatabaseCount('assignments', 0);
                $this->assertDatabaseCount('assignment_notifications', 0);
                $this->assertTrue(DB::selectOne("SELECT indisunique AND indisvalid AND indisready AS valid FROM pg_index WHERE indexrelid='assignments_active_request_unique'::regclass")->valid);
                $this->assertSame(2, (int) DB::selectOne("SELECT count(*) AS n FROM pg_trigger WHERE tgrelid IN ('assignments'::regclass,'assignment_revisions'::regclass) AND tgname IN ('permanent_assignment','retain_assignment_revisions') AND tgenabled='O'")->n);
            } finally {
                while (DB::connection('assignment_upgrade')->transactionLevel() > 0) {
                    DB::connection('assignment_upgrade')->rollBack();
                }
                DB::setDefaultConnection('pgsql');
                Schema::clearResolvedInstance('db.schema');
                DB::purge('assignment_upgrade');
            }
            $this->assertFalse(DB::table('pg_namespace')->where('nspname', $schema)->exists());
        }
    }
}
