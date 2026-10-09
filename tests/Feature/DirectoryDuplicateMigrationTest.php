<?php

namespace Tests\Feature;

use App\Http\Controllers\Administration\DirectoryController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CollectionFixtures;
use Tests\TestCase;

class DirectoryDuplicateMigrationTest extends TestCase
{
    use CollectionFixtures;

    public function test_populated_upgrade_refuses_existing_conflicts_and_preserves_all_original_values(): void
    {
        $schema = 'directory_upgrade_'.str_replace('-', '', Str::uuid()->toString());
        $c = config('database.connections.pgsql');
        $this->assertSame('ewaste_testing', $c['database']);
        $c['search_path'] = $schema;
        config(['database.connections.directory_upgrade' => $c]);
        DB::setDefaultConnection('directory_upgrade');
        Schema::clearResolvedInstance('db.schema');
        DB::beginTransaction();
        try {
            DB::statement('CREATE SCHEMA "'.$schema.'"');
            $files = glob(database_path('migrations/*.php'));
            sort($files);
            foreach ($files as $file) {
                if (basename($file) < '2026_10_09_000001_prevent_directory_duplicates.php') {
                    (require $file)->up();
                }
            }
            $this->directory();
            try {
                app(DirectoryController::class)->form('organization');
                $this->fail('Unmigrated forms must give an explicit maintenance response.');
            } catch (HttpException $e) {
                $this->assertSame(503, $e->getStatusCode());
                $this->assertStringContainsString('pending migration', $e->getMessage());
            }
            $first = DB::table('organizations')->first();
            $copy = (array) $first;
            $copy['id'] = Str::uuid()->toString();
            $copy['code'] = 'synthetic-conflict';
            $copy['name'] = strtoupper($first->name).'  ';
            DB::table('organizations')->insert($copy);
            try {
                (require database_path('migrations/2026_10_09_000001_prevent_directory_duplicates.php'))->up();
                $this->fail('Existing conflicts must block without reconciliation.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($copy['id'], $e->getMessage());
                $this->assertStringNotContainsString($first->name, $e->getMessage());
            }
            $this->assertFalse(Schema::hasColumn('organizations', 'brs_number'));
            $this->assertSame($copy['name'], DB::table('organizations')->where('id', $copy['id'])->value('name'));
            // Only this isolated synthetic fixture is reconciled to exercise the accepted upgrade path.
            DB::table('organizations')->where('id', $copy['id'])->update(['name' => 'Distinct fixture organization']);
            $before = [];
            foreach (DB::select('SELECT tablename FROM pg_tables WHERE schemaname=? ORDER BY tablename', [$schema]) as $t) {
                $cols = Schema::getColumnListing($t->tablename);
                $rows = DB::table($t->tablename)->select($cols)->get()->map(fn ($r) => json_encode($r))->all();
                sort($rows);
                $before[$t->tablename] = [$cols, $rows];
            }
            (require database_path('migrations/2026_10_09_000001_prevent_directory_duplicates.php'))->up();
            foreach ($before as $t => [$cols, $rows]) {
                $after = DB::table($t)->select($cols)->get()->map(fn ($r) => json_encode($r))->all();
                sort($after);
                $this->assertSame($rows, $after, $t);
            }
            $this->assertSame(3, (int) DB::selectOne("SELECT count(*) AS total FROM pg_indexes WHERE schemaname=? AND indexname IN ('organizations_name_identity','organizations_brs_identity','places_site_identity')", [$schema])->total);
            $this->assertNull(DB::table('organizations')->first()->brs_number);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        } finally {
            while (DB::connection('directory_upgrade')->transactionLevel() > 0) {
                DB::connection('directory_upgrade')->rollBack();
            }
            DB::setDefaultConnection('pgsql');
            Schema::clearResolvedInstance('db.schema');
            DB::purge('directory_upgrade');
        }
        $this->assertFalse(DB::table('pg_namespace')->where('nspname', $schema)->exists());
    }
}
