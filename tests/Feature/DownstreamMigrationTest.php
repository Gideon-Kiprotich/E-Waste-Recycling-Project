<?php

namespace Tests\Feature;

use App\Actions\AuthorizeBatchMedia;
use App\Support\Sanitization\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\BatchFixtures;
use Tests\TestCase;

class DownstreamMigrationTest extends TestCase
{
    use BatchFixtures;

    public function test_guarded_fresh_and_populated_upgrade_preserves_original_columns(): void
    {
        foreach ([false, true] as $populated) {
            $schema = 'downstream_upgrade_'.str_replace('-', '', Str::uuid()->toString());
            $c = config('database.connections.pgsql');
            $this->assertSame('ewaste_testing', $c['database']);
            $c['search_path'] = $schema;
            config(['database.connections.downstream_upgrade' => $c]);
            DB::setDefaultConnection('downstream_upgrade');
            Schema::clearResolvedInstance('db.schema');
            try {
                DB::beginTransaction();
                DB::statement('CREATE SCHEMA "'.$schema.'"');
                $files = glob(database_path('migrations/*.php'));
                sort($files);
                foreach ($files as $file) {
                    if (basename($file) < '2026_10_19_000001_create_downstream_movements.php') {
                        (require $file)->up();
                    }
                }
                if ($populated) {
                    $w = $this->batchWork();
                    $this->batchRecord($w);
                    $this->batchRecord($w, 'commit');
                    $this->batchRecord($w, 'output');
                    $this->batchRecord($w, 'measurement');
                    $this->batchRecord($w, 'accounted');
                    $a = app(AuthorizeBatchMedia::class);
                    $s = $w['media'][0];
                    $f = $this->handoverEvidence() + ['expected_version' => 0, 'expected_context' => $a->token($w['receiver']->fresh(), $w['batch'], $s), 'reason' => 'Synthetic existing batch assurance before upgrade', 'checks' => array_fill_keys(['identity_confirmed', 'safe_media', 'method_scope_known', 'no_unresolved_restriction'], 'YES')];
                    $a->record($w['receiver'], $w['batch'], $s, $f);
                    $this->handoverBoundary();
                    $this->mediaSatisfied($this->mediumWork($w, $s));
                    $returned = $this->mediaWork();
                    $this->mediaSatisfied($returned);
                    $this->reusePassed($returned);
                    $this->reuseRecord($returned, 'authorize');
                    $this->reuseRecord($returned, 'acknowledge', [], true);
                }
                $before = [];
                foreach (DB::select('SELECT tablename FROM pg_tables WHERE schemaname=? ORDER BY tablename', [$schema]) as $t) {
                    $cols = Schema::getColumnListing($t->tablename);
                    $before[$t->tablename] = ['columns' => $cols, 'rows' => DB::table($t->tablename)->select($cols)->get()->sortBy(fn ($r) => json_encode($r))->values()->toJson()];
                }
                foreach ($files as $file) {
                    if (basename($file) >= '2026_10_19_000001_create_downstream_movements.php') {
                        (require $file)->up();
                    }
                }
                foreach ($before as $table => $v) {
                    $this->assertSame($v['rows'], DB::table($table)->select($v['columns'])->get()->sortBy(fn ($r) => json_encode($r))->values()->toJson(), $table);
                }
                if ($populated) {
                    $this->assertSame('satisfied', app(Context::class)->state($w['media'][0]));
                }
                $this->assertSame(0, DB::table('downstream_records')->count());
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            } finally {
                while (DB::connection('downstream_upgrade')->transactionLevel() > 0) {
                    DB::connection('downstream_upgrade')->rollBack();
                }
                DB::setDefaultConnection('pgsql');
                Schema::clearResolvedInstance('db.schema');
                DB::purge('downstream_upgrade');
            }
            $this->assertFalse(DB::table('pg_namespace')->where('nspname', $schema)->exists());
        }
    }
}
