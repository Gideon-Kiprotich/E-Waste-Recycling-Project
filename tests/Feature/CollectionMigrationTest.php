<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\CollectionFixtures;
use Tests\TestCase;

class CollectionMigrationTest extends TestCase
{
    use CollectionFixtures;

    public function test_populated_upgrade_preserves_every_existing_row_and_seeds_no_directory(): void
    {
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1]);
        $schema = 'collection_upgrade_'.str_replace('-', '', Str::uuid()->toString());
        $c = config('database.connections.pgsql');
        $this->assertSame('ewaste_testing', $c['database']);
        $c['search_path'] = $schema;
        config(['database.connections.collection_upgrade' => $c]);
        DB::setDefaultConnection('collection_upgrade');
        Schema::clearResolvedInstance('db.schema');
        DB::beginTransaction();
        try {
            DB::statement('CREATE SCHEMA "'.$schema.'"');
            $files = glob(database_path('migrations/*.php'));
            sort($files);
            foreach ($files as $file) {
                if (basename($file) < '2026_10_06_000002_create_collection_requests.php') {
                    (require $file)->up();
                }
            }
            $owner = User::factory()->create();
            $this->device($owner);
            $this->device($owner, 'standalone_part', 'ssd');
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            $before = [];
            foreach (DB::select('SELECT tablename FROM pg_tables WHERE schemaname=? ORDER BY tablename', [$schema]) as $table) {
                $columns = Schema::getColumnListing($table->tablename);
                $before[$table->tablename] = [$columns, DB::table($table->tablename)->select($columns)->get()->toJson()];
            }
            foreach ($files as $file) {
                if (basename($file) >= '2026_10_06_000002_create_collection_requests.php') {
                    DB::transaction(fn () => (require $file)->up());
                }
            }
            foreach ($before as $table => [$columns, $rows]) {
                $this->assertSame($rows, DB::table($table)->select($columns)->when($table === 'categories', fn ($q) => $q->where('code', '<>', 'processor_media'))->get()->toJson(), $table);
            }
            $this->assertSame(0, DB::table('memberships')->whereNotNull('organization_id')->count());
            $this->assertSame(0, DB::table('memberships')->where('version', '<>', 1)->count());
            $this->assertDatabaseCount('assignments', 0);
            $this->assertDatabaseHas('categories', ['code' => 'processor_media', 'supported' => false]);
            foreach (['organizations', 'places', 'place_categories', 'service_areas', 'requests', 'request_items', 'request_private_details'] as $table) {
                $this->assertDatabaseCount($table, 0);
            }
            $this->assertTrue(Schema::hasColumn('request_items', 'initial_observation_id'));
            $this->assertSame(1, (int) DB::selectOne("SELECT count(*) AS total FROM pg_indexes WHERE schemaname=? AND indexname='request_items_active_item_unique'", [$schema])->total);
        } finally {
            while (DB::connection('collection_upgrade')->transactionLevel() > 0) {
                DB::connection('collection_upgrade')->rollBack();
            }DB::setDefaultConnection('pgsql');
            Schema::clearResolvedInstance('db.schema');
            DB::purge('collection_upgrade');
        }
        $this->assertFalse(DB::table('pg_namespace')->where('nspname', $schema)->exists());
    }
}
