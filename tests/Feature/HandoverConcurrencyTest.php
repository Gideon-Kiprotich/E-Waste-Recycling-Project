<?php

namespace Tests\Feature;

use App\Actions\CustodyHandovers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\HandoverFixtures;
use Tests\TestCase;

class HandoverConcurrencyTest extends TestCase
{
    use HandoverFixtures;

    public function test_real_competing_initiation_acknowledgment_and_withdrawal_commit_only_one_outcome(): void
    {
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1]);
        foreach (['initiation', 'acknowledgment', 'withdrawal'] as $kind) {
            $schema = 'handover_race_'.str_replace('-', '', Str::uuid()->toString());
            $c = config('database.connections.pgsql');
            $this->assertSame('ewaste_testing', $c['database']);
            $c['search_path'] = $schema;
            config(['database.connections.handover_race' => $c]);
            DB::setDefaultConnection('handover_race');
            Schema::clearResolvedInstance('db.schema');
            $file = tempnam(sys_get_temp_dir(), 'ewaste-handover-race-');
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
                $w = $this->handoverWork();
                $init = $this->initiateFields($w['sender'], $w['receiver']);
                $id = $kind === 'initiation' ? null : app(CustodyHandovers::class)->initiate($w['sender'], $init);
                $child = $id ? $this->acknowledgeFields($id) : array_replace($init, ['idempotency_key' => Str::uuid()->toString()]);
                DB::beginTransaction();
                if ($kind === 'initiation') {
                    $id = app(CustodyHandovers::class)->initiate($w['sender'], $init);
                } else {
                    app(CustodyHandovers::class)->change($kind === 'withdrawal' ? $w['sender'] : $w['receiver'], $id, $kind === 'withdrawal' ? 'withdrawn' : 'acknowledged', $this->acknowledgeFields($id, $kind === 'withdrawal' ? ['withdrawn'] : []));
                }
                file_put_contents($file, json_encode(['schema' => $schema, 'kind' => $kind === 'initiation' ? 'initiation' : 'acknowledgment', 'actor' => $kind === 'initiation' ? $w['sender']->id : $w['receiver']->id, 'handover' => $id, 'fields' => $child, 'signal' => $file.'.ready'], JSON_THROW_ON_ERROR));
                $process = proc_open([PHP_BINARY, base_path('tests/Support/handover-race-worker.php'), $file], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
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
                $this->assertDatabaseCount('handovers', 1);
                $this->assertDatabaseCount('handover_receipts', $kind === 'acknowledgment' ? 1 : 0);
                $this->assertDatabaseCount('handover_operations', $kind === 'initiation' ? 1 : 2);
                $this->assertSame($kind === 'acknowledgment' ? 3 : 2, DB::table('custody_current')->value('version'));
                $this->assertSame($kind === 'withdrawal' ? 'withdrawn' : ($kind === 'initiation' ? 'pending' : 'accepted'), DB::table('handover_items')->value('state'));
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
                while (DB::connection('handover_race')->transactionLevel() > 0) {
                    DB::connection('handover_race')->rollBack();
                }
                DB::connection('handover_race')->statement('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
                DB::setDefaultConnection('pgsql');
                Schema::clearResolvedInstance('db.schema');
                DB::purge('handover_race');
                @unlink($file);
                @unlink($file.'.ready');
            }
            $this->assertFalse(DB::table('pg_namespace')->where('nspname', $schema)->exists());
        }
    }
}
