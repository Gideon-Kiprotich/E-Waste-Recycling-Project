<?php

namespace Tests\Feature;

use App\Actions\AuthorizeBatchMedia;
use App\Actions\InspectItems;
use App\Support\Sanitization\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\BatchFixtures;
use Tests\TestCase;

class RestrictionMigrationTest extends TestCase
{
    use BatchFixtures;

    public function test_fresh_and_populated_upgrade_preserves_original_records_without_inventing_cases(): void
    {
        foreach ([false, true] as $populated) {
            $schema = 'restriction_upgrade_'.str_replace('-', '', Str::uuid()->toString());
            $config = config('database.connections.pgsql');
            $this->assertSame('ewaste_testing', $config['database']);
            $config['search_path'] = $schema;
            config(['database.connections.restriction_upgrade' => $config]);
            DB::setDefaultConnection('restriction_upgrade');
            Schema::clearResolvedInstance('db.schema');
            try {
                DB::beginTransaction();
                DB::statement('CREATE SCHEMA "'.$schema.'"');
                $files = glob(database_path('migrations/*.php'));
                sort($files);
                foreach ($files as $file) {
                    if (basename($file) < '2026_10_19_000006_create_restriction_review.php') {
                        (require $file)->up();
                    }
                }
                if ($populated) {
                    $w = $this->inspectionWork();
                    $fields = $this->inspectionFields($w['ids'][0]);
                    $fields['answers']['safety_concern'] = 'YES';
                    app(InspectItems::class)->inspect($w['receiver'], $w['ids'][0], $fields);
                    $this->handoverBoundary();
                    $batch = $this->batchWork();
                    foreach (['draft', 'commit', 'output', 'measurement', 'accounted'] as $kind) {
                        $this->batchRecord($batch, $kind);
                    }
                    $authority = app(AuthorizeBatchMedia::class);
                    $medium = $batch['media'][0];
                    $authority->record($batch['receiver'], $batch['batch'], $medium, $this->handoverEvidence() + ['expected_version' => 0, 'expected_context' => $authority->token($batch['receiver'], $batch['batch'], $medium), 'reason' => 'Synthetic retained batch authority before upgrade', 'checks' => array_fill_keys(['identity_confirmed', 'safe_media', 'method_scope_known', 'no_unresolved_restriction'], 'YES')]);
                    $this->handoverBoundary();
                    $this->mediaSatisfied($this->mediumWork($batch, $medium));
                    $returned = $this->mediaWork();
                    $this->mediaSatisfied($returned);
                    $this->reusePassed($returned);
                    $this->reuseRecord($returned, 'authorize');
                    $this->reuseRecord($returned, 'acknowledge', [], true);
                }
                $before = [];
                foreach (DB::select('SELECT tablename FROM pg_tables WHERE schemaname=? ORDER BY tablename', [$schema]) as $table) {
                    $columns = Schema::getColumnListing($table->tablename);
                    $before[$table->tablename] = ['columns' => $columns, 'rows' => DB::table($table->tablename)->select($columns)->get()->sortBy(fn ($r) => json_encode($r))->values()->toJson()];
                }
                foreach ($files as $file) {
                    if (basename($file) >= '2026_10_19_000006_create_restriction_review.php') {
                        (require $file)->up();
                    }
                }
                foreach ($before as $table => $snapshot) {
                    $this->assertSame($snapshot['rows'], DB::table($table)->select($snapshot['columns'])->get()->sortBy(fn ($r) => json_encode($r))->values()->toJson(), $table);
                }
                $this->assertDatabaseCount('restriction_cases', 0);
                $this->assertDatabaseCount('restriction_records', 0);
                $this->assertDatabaseCount('restriction_epochs', 0);
                $this->assertDatabaseCount('restriction_stamps', 0);
                if ($populated) {
                    $this->assertTrue((bool) DB::selectOne('SELECT restriction_inspection_blocked(?) AS blocked', [$w['ids'][0]])->blocked);
                    $this->assertSame('satisfied', app(Context::class)->state($medium));
                    $this->assertSame('released', DB::table('items')->where('subject_id', $returned['ids'][0])->value('processing_state'));
                }
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            } finally {
                while (DB::connection('restriction_upgrade')->transactionLevel() > 0) {
                    DB::connection('restriction_upgrade')->rollBack();
                }
                DB::setDefaultConnection('pgsql');
                Schema::clearResolvedInstance('db.schema');
                DB::purge('restriction_upgrade');
            }
            $this->assertFalse(DB::table('pg_namespace')->where('nspname', $schema)->exists());
        }
    }
}
