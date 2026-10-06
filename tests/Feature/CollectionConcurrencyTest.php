<?php

namespace Tests\Feature;

use App\Actions\CollectionRequests;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\CollectionFixtures;
use Tests\TestCase;

class CollectionConcurrencyTest extends TestCase
{
    use CollectionFixtures;

    public function test_real_competing_transaction_waits_then_rejects_the_committed_reservation(): void
    {
        $this->withoutVite();
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1]);
        $configuration = config('database.connections.pgsql');
        $this->assertSame('ewaste_testing', $configuration['database']);
        $schema = 'collection_fixture_'.str_replace('-', '', Str::uuid()->toString());
        $configuration['search_path'] = $schema;
        config(['database.connections.collection_fixture' => $configuration]);
        DB::setDefaultConnection('collection_fixture');
        Schema::clearResolvedInstance('db.schema');
        $file = tempnam(sys_get_temp_dir(), 'ewaste-race-');
        chmod($file, 0600);
        $signal = $file.'.ready';
        $process = null;
        $pipes = [];
        try {
            DB::statement('CREATE SCHEMA "'.$schema.'"');
            $this->assertSame($schema, DB::selectOne('SELECT current_schema() AS name')->name);
            $files = glob(database_path('migrations/*.php'));
            sort($files);
            foreach ($files as $migration) {
                DB::transaction(fn () => (require $migration)->up());
            }
            $this->directory();
            $owner = User::factory()->create();
            $device = $this->device($owner);
            $fields = $this->requestFields([$device->subject_id]);
            $competing = $fields;
            $competing['idempotency_key'] = Str::uuid()->toString();
            file_put_contents($file, json_encode(['schema' => $schema, 'owner' => $owner->id, 'fields' => $competing, 'signal' => $signal], JSON_THROW_ON_ERROR));
            DB::beginTransaction();
            $id = app(CollectionRequests::class)->submit($owner, $fields);
            $process = proc_open([PHP_BINARY, base_path('tests/Support/collection-race-worker.php'), $file], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
            $this->assertIsResource($process);
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $deadline = microtime(true) + 10;
            $waiting = false;
            do {
                if (is_file($signal)) {
                    $pid = (int) file_get_contents($signal);
                    $state = DB::connection('pgsql')->selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [$pid]);
                    $waiting = $state?->wait_event_type === 'Lock';
                }
                if ($waiting) {
                    break;
                }usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertTrue($waiting, 'The second real connection must reach a database lock while the first transaction is uncommitted.');
            $this->assertSame('', stream_get_contents($pipes[1]));
            DB::commit();
            stream_set_blocking($pipes[1], true);
            stream_set_timeout($pipes[1], 10);
            $result = stream_get_contents($pipes[1]);
            $this->assertSame('reservation_rejected', $result);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $pipes = [];
            $this->assertSame(0, proc_close($process));
            $process = null;
            $this->assertDatabaseCount('requests', 1);
            $this->assertDatabaseCount('request_private_details', 1);
            $this->assertDatabaseCount('request_items', 1);
            $this->assertDatabaseHas('request_items', ['request_id' => $id, 'item_id' => $device->subject_id, 'active' => true]);
            $this->assertSame(1, DB::table('domain_events')->where('aggregate_kind', 'request')->count());
            $this->assertSame(1, DB::table('commands')->where('action', 'submit_request')->count());
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }proc_close($process);
            }
            while (DB::connection('collection_fixture')->transactionLevel() > 0) {
                DB::connection('collection_fixture')->rollBack();
            }
            DB::connection('collection_fixture')->statement('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
            DB::setDefaultConnection('pgsql');
            Schema::clearResolvedInstance('db.schema');
            DB::purge('collection_fixture');
            if (is_file($file)) {
                unlink($file);
            }if (is_file($signal)) {
                unlink($signal);
            }
        }
        $this->assertFalse(DB::table('pg_namespace')->where('nspname', $schema)->exists());
    }
}
