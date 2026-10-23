<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Handovers\Notices;
use App\Support\Notices\Milestones;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\HandoverFixtures;
use Tests\TestCase;

class ReportingMigrationTest extends TestCase
{
    use HandoverFixtures;

    public function test_fresh_populated_and_late_old_transaction_cutover_preserve_original_rows(): void
    {
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1]);
        $this->assertSame('ewaste_testing', config('database.connections.pgsql.database'));
        foreach (['fresh', 'populated'] as $scenario) {
            $schema = 'reporting_upgrade_'.str_replace('-', '', (string) Str::uuid());
            $config = config('database.connections.pgsql');
            $config['search_path'] = $schema;
            config(['database.connections.reporting_upgrade' => $config, 'database.connections.reporting_late' => $config]);
            DB::setDefaultConnection('reporting_upgrade');
            Schema::clearResolvedInstance('db.schema');
            try {
                DB::statement('CREATE SCHEMA "'.$schema.'"');
                foreach (glob(database_path('migrations/*.php')) as $file) {
                    if (basename($file) < '2026_10_23_000001_create_reporting_workflows.php') {
                        DB::transaction(fn () => (require $file)->up());
                    }
                }
                if ($scenario === 'populated') {
                    $w = $this->handoverWork(1, true);
                    $this->initiateHandover($w);
                    app(Notices::class)->deliver();
                    $notice = DB::table('handover_notifications')->firstOrFail();
                    DB::table('handover_notifications')->where('id', $notice->id)->update(['read_at' => now()]);
                }
                $before = [];
                foreach (DB::table('pg_tables')->where('schemaname', $schema)->orderBy('tablename')->pluck('tablename') as $table) {
                    $columns = DB::select('SELECT column_name FROM information_schema.columns WHERE table_schema=? AND table_name=? ORDER BY ordinal_position', [$schema, $table]);
                    $names = array_column($columns, 'column_name');
                    $sql = 'SELECT to_jsonb(t)::text value FROM (SELECT '.implode(',', array_map(fn ($v) => '"'.$v.'"', $names)).' FROM "'.$table.'") t ORDER BY value';
                    $before[$table] = [$sql, DB::select($sql)];
                }
                DB::connection('reporting_late')->beginTransaction();
                DB::connection('reporting_late')->select('SELECT transaction_timestamp(),txid_current()');
                DB::transaction(fn () => (require database_path('migrations/2026_10_23_000001_create_reporting_workflows.php'))->up());
                foreach ($before as [$sql,$rows]) {
                    $this->assertEquals($rows, DB::select($sql));
                }
                $this->assertSame(0, DB::table('milestone_notifications')->count());
                $this->assertSame(0, DB::table('report_exports')->count());
                $this->assertSame(0, app(Milestones::class)->deliver());
                DB::setDefaultConnection('reporting_late');
                $lateOwner = User::factory()->create();
                $lateItem = $this->device($lateOwner);
                DB::commit();
                DB::setDefaultConnection('reporting_upgrade');
                $old = DB::table('notification_outbox as o')->join('domain_events as e', 'e.id', '=', 'o.event_id')->where('e.subject_id', $lateItem->subject_id)->first(['o.*']);
                $this->assertNull($old->delivery_version);
                $this->assertSame('pending', $old->state);
                $this->assertSame(0, app(Milestones::class)->deliver());
                $owner = User::factory()->create();
                $this->device($owner);
                $this->assertSame(1, app(Milestones::class)->deliver());
                $this->assertSame('pending', DB::table('notification_outbox')->where('id', $old->id)->value('state'));
            } finally {
                foreach (['reporting_late', 'reporting_upgrade'] as $connection) {
                    while (DB::connection($connection)->transactionLevel()) {
                        DB::connection($connection)->rollBack();
                    }
                }
                DB::setDefaultConnection('reporting_upgrade');
                DB::statement('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
                DB::purge('reporting_late');
                DB::purge('reporting_upgrade');
                DB::setDefaultConnection('pgsql');
                Schema::clearResolvedInstance('db.schema');
            }
        }
    }
}
