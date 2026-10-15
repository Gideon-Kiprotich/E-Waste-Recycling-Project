<?php

namespace Tests\Feature;

use App\Actions\CollectionRequests;
use App\Actions\RecordVisit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\ReceiptFixtures;
use Tests\TestCase;

class ReceiptConcurrencyTest extends TestCase
{
    use ReceiptFixtures;

    public function test_competing_receipts_and_cancellation_have_real_transaction_boundaries(): void
    {
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1]);
        foreach (['competing_receipt', 'cancel_loses', 'receipt_loses', 'direct'] as $operation) {
            $schema = 'receipt_race_'.str_replace('-', '', Str::uuid()->toString());
            $c = config('database.connections.pgsql');
            $this->assertSame('ewaste_testing', $c['database']);
            $c['search_path'] = $schema;
            config(['database.connections.receipt_race' => $c]);
            DB::setDefaultConnection('receipt_race');
            Schema::clearResolvedInstance('db.schema');
            $file = tempnam(sys_get_temp_dir(), 'ewaste-route-race-');
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
                [$admin,$owner,$collector,$route,$ordered] = $this->pickupWork();
                $request = DB::table('assignments')->where('id', $ordered[0])->value('request_id');
                $receiptFields = $this->visitFields($request);
                $cancelFields = ['operation' => 'cancel', 'items' => DB::table('request_items')->where('request_id', $request)->pluck('item_id')->all(), 'expected_version' => $receiptFields['expected_version'], 'idempotency_key' => Str::uuid()->toString(), 'reason' => 'Synthetic cancellation race'];
                DB::beginTransaction();
                if ($operation === 'receipt_loses') {
                    app(CollectionRequests::class)->change($owner, $request, $cancelFields);
                } else {
                    app(RecordVisit::class)->record($collector, $request, $receiptFields);
                }
                $childFields = $receiptFields;
                $childFields['idempotency_key'] = Str::uuid()->toString();
                file_put_contents($file, json_encode(['schema' => $schema, 'operation' => $operation === 'cancel_loses' ? 'cancel' : ($operation === 'direct' ? 'direct' : 'receipt'), 'actor' => $operation === 'cancel_loses' ? $owner->id : $collector->id, 'request' => $request, 'fields' => $operation === 'cancel_loses' ? $cancelFields : $childFields, 'receipt' => (array) DB::table('first_receipts')->first(), 'signal' => $file.'.ready'], JSON_THROW_ON_ERROR));
                $process = proc_open([PHP_BINARY, base_path('tests/Support/receipt-race-worker.php'), $file], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                fclose($pipes[0]);
                stream_set_blocking($pipes[1], false);
                $waiting = false;
                $end = microtime(true) + 10;
                do {
                    if (is_file($file.'.ready')) {
                        $state = DB::connection('pgsql')->selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid=?', [(int) file_get_contents($file.'.ready')]);
                        $waiting = $state?->wait_event_type === 'Lock';
                    }if ($waiting) {
                        break;
                    }usleep(10000);
                } while (microtime(true) < $end);
                $this->assertTrue($waiting, 'Competing '.$operation.' must reach a PostgreSQL lock.');
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
                $this->assertDatabaseCount('first_receipts', $operation === 'receipt_loses' ? 0 : 1);
                $this->assertDatabaseCount('receipt_visits', $operation === 'receipt_loses' ? 0 : 1);
                $this->assertSame($operation === 'receipt_loses' ? 'cancelled' : 'collected', DB::table('request_items')->where('request_id', $request)->value('outcome'));
                $this->assertFalse(DB::table('request_items')->where('request_id', $request)->value('active'));
                $this->assertSame($operation === 'receipt_loses' ? 1 : 2, DB::table('custody_current')->value('version'));
            } finally {
                if (is_resource($process)) {
                    proc_terminate($process);
                    foreach ($pipes as $p) {
                        if (is_resource($p)) {
                            fclose($p);
                        }
                    }proc_close($process);
                }while (DB::connection('receipt_race')->transactionLevel() > 0) {
                    DB::connection('receipt_race')->rollBack();
                }DB::connection('receipt_race')->statement('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
                DB::setDefaultConnection('pgsql');
                Schema::clearResolvedInstance('db.schema');
                DB::purge('receipt_race');
                @unlink($file);
                @unlink($file.'.ready');
            }
            $this->assertFalse(DB::table('pg_namespace')->where('nspname', $schema)->exists());
        }
    }
}
