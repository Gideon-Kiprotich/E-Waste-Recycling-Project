<?php

namespace Tests\Feature;

use App\Actions\RecordSanitization;
use App\Models\User;
use App\Support\Sanitization\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\SanitizationFixtures;
use Tests\TestCase;

class MediaInventoryMigrationTest extends TestCase
{
    use SanitizationFixtures;

    public function test_fresh_and_populated_upgrade_preserves_existing_records(): void
    {
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1]);
        foreach ([false, true] as $populated) {
            $schema = 'media_upgrade_'.str_replace('-', '', Str::uuid()->toString());
            $c = config('database.connections.pgsql');
            $this->assertSame('ewaste_testing', $c['database']);
            $c['search_path'] = $schema;
            config(['database.connections.media_upgrade' => $c]);
            DB::setDefaultConnection('media_upgrade');
            Schema::clearResolvedInstance('db.schema');
            DB::beginTransaction();
            try {
                DB::statement('CREATE SCHEMA "'.$schema.'"');
                $files = glob(database_path('migrations/*.php'));
                sort($files);
                foreach ($files as $f) {
                    if (basename($f) < '2026_10_16_000006_create_media_inventory.php') {
                        (require $f)->up();
                    }
                }
                $before = [];
                if ($populated) {
                    // The old fixture journey does not invoke the new snapshot before its migration.
                    $w = $this->mediaWork();
                    $this->app->instance(Context::class, new class extends Context
                    {
                        public function snapshot(User $actor, object $row): array
                        {
                            $ac = DB::table('assessment_current')->where('subject_id', $row->subject_id)->first();
                            $place = DB::table('parties as p')->join('places as l', 'l.id', '=', 'p.place_id')->join('organizations as o', 'o.id', '=', 'l.organization_id')->where('p.id', $row->party_id)->first(['l.version as facility_version', 'o.version as operator_version']);

                            return ['source_subject_version' => (int) $row->subject_version, 'assessment_version' => (int) $ac->version, 'assessment_id' => $ac->assessment_id, 'decision_id' => $ac->decision_id, 'facility_party_id' => $row->party_id, 'facility_version' => (int) $place->facility_version, 'operator_version' => (int) $place->operator_version, 'custody_event_id' => $row->last_event_id, 'custody_version' => (int) $row->custody_version, 'membership_id' => $actor->membership->id, 'membership_version' => (int) $actor->membership->version, 'actor_session_version' => (int) $actor->session_version];
                        }
                    });
                    $historicalFields = $this->mediaFields($w);
                    $historicalRecord = $this->mediaRecord($w, 'attempt', $historicalFields);
                    $this->mediaRecord($w, 'verification');
                    $this->mediaRecord($w, 'validation');
                    $this->app->forgetInstance(Context::class);

                }
                foreach (DB::select('SELECT tablename FROM pg_tables WHERE schemaname=? ORDER BY tablename', [$schema]) as $t) {
                    $before[$t->tablename] = DB::table($t->tablename)->get()->toArray();
                }
                (require database_path('migrations/2026_10_16_000006_create_media_inventory.php'))->up();
                (require database_path('migrations/2026_10_16_000007_protect_processor_media_boundary.php'))->up();
                (require database_path('migrations/2026_10_16_000008_require_explicit_media_na.php'))->up();
                foreach ($before as $t => $rows) {
                    $after = DB::table($t)->get()->toArray();
                    // New context columns are additive; all original field values stay identical.
                    foreach ($after as $index => $row) {
                        $after[$index] = (object) array_intersect_key((array) $row, (array) ($rows[$index] ?? []));
                    }
                    if ($t !== 'categories') {
                        $this->assertEquals($rows, $after, $t);
                    } else {
                        $original = DB::table($t)->where('code', '<>', 'processor_media')->get()->toArray();
                        $this->assertEquals($rows, $original);
                        $this->assertCount(count($rows) + 1, $after);
                    }
                }
                $this->assertDatabaseCount('media_inventory_records', 0);
                if ($populated) {
                    $this->assertSame($historicalRecord, app(RecordSanitization::class)->record($w['receiver'], $w['ids'][0], 'attempt', $historicalFields));
                    $this->assertSame('satisfied', app(Context::class)->state($w['ids'][0]));
                    $this->assertDatabaseCount('sanitization_records', 3);
                }
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            } finally {
                while (DB::connection('media_upgrade')->transactionLevel() > 0) {
                    DB::connection('media_upgrade')->rollBack();
                }
                DB::setDefaultConnection('pgsql');
                Schema::clearResolvedInstance('db.schema');
                DB::purge('media_upgrade');
            }
            $this->assertFalse(DB::table('pg_namespace')->where('nspname', $schema)->exists());
        }
    }
}
