<?php

namespace Tests\Feature;

use App\Actions\InspectItems;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\InspectionFixtures;
use Tests\TestCase;

class InspectionConcurrencyTest extends TestCase
{
    use InspectionFixtures;

    public function test_real_competing_inspections_and_decisions_reject_stale_work_after_waiting_for_commit(): void
    {
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1]);
        foreach (['inspection', 'decision'] as $kind) {
            $schema = 'inspection_race_'.str_replace('-', '', Str::uuid()->toString());
            $c = config('database.connections.pgsql');
            $this->assertSame('ewaste_testing', $c['database']);
            $c['search_path'] = $schema;
            config(['database.connections.inspection_race' => $c]);
            DB::setDefaultConnection('inspection_race');
            Schema::clearResolvedInstance('db.schema');
            $file = tempnam(sys_get_temp_dir(), 'ewaste-inspection-race-');
            chmod($file, 0600);
            $process = null;
            $pipes = [];
            try {
                DB::statement('CREATE SCHEMA "'.$schema.'"');
                $files = glob(database_path('migrations/*.php'));
                sort($files);
                foreach ($files as $m) {
                    DB::transaction(fn () => (require $m)->up());
                }
                $w = $this->inspectionWork('laptop');
                $subject = $w['ids'][0];
                $action = app(InspectItems::class);
                if ($kind === 'decision') {
                    $action->inspect($w['receiver'], $subject, $this->inspectionFields($subject, 'laptop-healthy'));
                }
                $fields = $kind === 'inspection' ? $this->inspectionFields($subject, 'laptop-healthy') : $this->decisionFields($subject);
                $child = array_replace($fields, ['idempotency_key' => Str::uuid()->toString()]);
                DB::beginTransaction();
                $kind === 'inspection' ? $action->inspect($w['receiver'], $subject, $fields) : $action->decide($w['receiver'], $subject, $fields);
                file_put_contents($file, json_encode(['schema' => $schema, 'kind' => $kind, 'actor' => $w['receiver']->id, 'subject' => $subject, 'fields' => $child, 'signal' => $file.'.ready'], JSON_THROW_ON_ERROR));
                $process = proc_open([PHP_BINARY, base_path('tests/Support/inspection-race-worker.php'), $file], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                fclose($pipes[0]);
                stream_set_blocking($pipes[1], false);
                $waiting = false;
                $end = microtime(true) + 10;
                do {
                    if (is_file($file.'.ready')) {
                        $state = DB::connection('pgsql')->selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid=?', [(int) file_get_contents($file.'.ready')]);
                        $waiting = $state?->wait_event_type === 'Lock';
                    }
                    if ($waiting) {
                        break;
                    }
                    usleep(10000);
                } while (microtime(true) < $end);
                $this->assertTrue($waiting, 'Competing '.$kind.' must wait on a real PostgreSQL lock.');
                $this->assertSame('', stream_get_contents($pipes[1]));
                DB::commit();
                stream_set_blocking($pipes[1], true);
                stream_set_timeout($pipes[1], 15);
                $this->assertSame('conflict_rejected', stream_get_contents($pipes[1]));
                fclose($pipes[1]);
                fclose($pipes[2]);
                $pipes = [];
                $this->assertSame(0, proc_close($process));
                $process = null;
                $this->assertDatabaseCount('assessments', 1);
                $this->assertDatabaseCount('decisions', $kind === 'decision' ? 1 : 0);
                $this->assertSame($kind === 'decision' ? 2 : 1, DB::table('assessment_current')->value('version'));
                $this->assertSame(3, DB::table('custody_current')->where('subject_id', $subject)->value('version'));
                $this->assertDatabaseCount('first_receipts', 1);
                $this->assertDatabaseCount('handover_receipts', 1);
            } finally {
                if (is_resource($process)) {
                    proc_terminate($process);
                    foreach ($pipes as $p) {
                        if (is_resource($p)) {
                            fclose($p);
                        }
                    }
                    proc_close($process);
                }
                while (DB::connection('inspection_race')->transactionLevel() > 0) {
                    DB::connection('inspection_race')->rollBack();
                }
                DB::connection('inspection_race')->statement('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
                DB::setDefaultConnection('pgsql');
                Schema::clearResolvedInstance('db.schema');
                DB::purge('inspection_race');
                @unlink($file);
                @unlink($file.'.ready');
            }
            $this->assertFalse(DB::table('pg_namespace')->where('nspname', $schema)->exists());
        }
    }
}
