<?php

namespace Tests\Feature;

use App\Actions\CollectionRequests;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\CollectionFixtures;
use Tests\TestCase;

class DirectoryReviewMigrationTest extends TestCase
{
    use CollectionFixtures;

    public function test_populated_upgrade_preserves_original_columns_rows_and_private_request_data(): void
    {
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1]);
        $schema = 'review_upgrade_'.str_replace('-', '', Str::uuid()->toString());
        $configuration = config('database.connections.pgsql');
        $this->assertSame('ewaste_testing', $configuration['database']);
        $configuration['search_path'] = $schema;
        config(['database.connections.review_upgrade' => $configuration]);
        DB::setDefaultConnection('review_upgrade');
        Schema::clearResolvedInstance('db.schema');
        DB::beginTransaction();
        try {
            DB::statement('CREATE SCHEMA "'.$schema.'"');
            $files = glob(database_path('migrations/*.php'));
            sort($files);
            foreach ($files as $file) {
                if (basename($file) < '2026_10_06_000004_create_directory_review.php') {
                    (require $file)->up();
                }
            }
            $this->directory();
            $owner = User::factory()->create();
            $item = $this->device($owner, 'standalone_part', 'ssd');
            $request = app(CollectionRequests::class)->submit($owner, $this->requestFields([$item->subject_id]));
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            $before = [];
            foreach (DB::select('SELECT tablename FROM pg_tables WHERE schemaname=? ORDER BY tablename', [$schema]) as $table) {
                $columns = Schema::getColumnListing($table->tablename);
                $before[$table->tablename] = [$columns, DB::table($table->tablename)->select($columns)->get()->toJson()];
            }
            (require database_path('migrations/2026_10_06_000004_create_directory_review.php'))->up();
            foreach ($before as $table => [$columns,$rows]) {
                $this->assertSame($rows, DB::table($table)->select($columns)->get()->toJson(), $table);
            }
            $this->assertDatabaseHas('requests', ['id' => $request, 'review_state' => 'pending', 'review_id' => null]);
            $this->assertDatabaseCount('request_reviews', 0);
            $this->assertDatabaseCount('directory_revisions', 0);
            $this->assertSame(1, DB::table('storage_assets')->count());
            $this->assertSame(2, (int) DB::selectOne("SELECT count(*) AS total FROM pg_trigger WHERE tgrelid IN ('directory_revisions'::regclass,'request_reviews'::regclass) AND tgname IN ('retain_directory_revisions','retain_request_reviews') AND tgenabled='O'")->total);
        } finally {
            while (DB::connection('review_upgrade')->transactionLevel() > 0) {
                DB::connection('review_upgrade')->rollBack();
            }
            DB::setDefaultConnection('pgsql');
            Schema::clearResolvedInstance('db.schema');
            DB::purge('review_upgrade');
        }
        $this->assertFalse(DB::table('pg_namespace')->where('nspname', $schema)->exists());
    }
}
