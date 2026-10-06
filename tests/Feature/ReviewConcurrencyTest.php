<?php

namespace Tests\Feature;

use App\Actions\CollectionRequests;
use App\Actions\ReviewRequest;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\CollectionFixtures;
use Tests\TestCase;

class ReviewConcurrencyTest extends TestCase
{
    use CollectionFixtures;

    public function test_real_competing_reviews_and_configuration_changes_preserve_reservations_and_invalidate_stale_decisions(): void
    {
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1]);
        foreach (['review', 'configuration'] as $operation) {
            $schema = 'review_fixture_'.str_replace('-', '', Str::uuid()->toString());
            $c = config('database.connections.pgsql');
            $this->assertSame('ewaste_testing', $c['database']);
            $c['search_path'] = $schema;
            config(['database.connections.review_fixture' => $c]);
            DB::setDefaultConnection('review_fixture');
            Schema::clearResolvedInstance('db.schema');
            $file = tempnam(sys_get_temp_dir(), 'ewaste-review-race-');
            chmod($file, 0600);
            $signal = $file.'.ready';
            $process = null;
            $pipes = [];
            try {
                DB::statement('CREATE SCHEMA "'.$schema.'"');
                $files = glob(database_path('migrations/*.php'));
                sort($files);
                foreach ($files as $migration) {
                    DB::transaction(fn () => (require $migration)->up());
                }
                $this->directory();
                $owner = User::factory()->create();
                $item = $this->device($owner, 'standalone_part', 'ssd');
                $id = app(CollectionRequests::class)->submit($owner, $this->requestFields([$item->subject_id], 'drop_off'));
                $admins = [];
                for ($i = 0; $i < 2; $i++) {
                    $admin = User::factory()->create();
                    $admin->membership()->update(['role' => Role::Administrator]);
                    $admins[] = $admin->refresh();
                }
                $fields = ['decision' => 'ready', 'reason' => 'Synthetic race review.', 'expected_version' => 1, 'idempotency_key' => Str::uuid()->toString()];
                $competing = $fields;
                $competing['idempotency_key'] = Str::uuid()->toString();
                $target = $id;
                if ($operation === 'configuration') {
                    $org = DB::table('organizations')->first();
                    $target = $org->id;
                    $competing = ['name' => $org->name, 'kind' => $org->kind, 'review_status' => 'suspended', 'contact' => 'Synthetic contact only', 'reason' => 'Synthetic suspension race.', 'expected_version' => 1, 'idempotency_key' => Str::uuid()->toString()];
                }
                file_put_contents($file, json_encode(['schema' => $schema, 'actor' => $admins[1]->id, 'operation' => $operation, 'id' => $target, 'fields' => $competing, 'signal' => $signal], JSON_THROW_ON_ERROR));
                $custody = DB::table('custody_current')->orderBy('subject_id')->get()->toJson();
                DB::beginTransaction();
                app(ReviewRequest::class)->decide($admins[0], $id, $fields);
                $process = proc_open([PHP_BINARY, base_path('tests/Support/review-race-worker.php'), $file], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                fclose($pipes[0]);
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                $deadline = microtime(true) + 10;
                $waiting = false;
                do {
                    if (is_file($signal)) {
                        $state = DB::connection('pgsql')->selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid=?', [(int) file_get_contents($signal)]);
                        $waiting = $state?->wait_event_type === 'Lock';
                    }
                    if ($waiting) {
                        break;
                    } usleep(10000);
                } while (microtime(true) < $deadline);
                $this->assertTrue($waiting, 'The competing real connection must wait at the transaction gate.');
                $this->assertSame('', stream_get_contents($pipes[1]));
                DB::commit();
                stream_set_blocking($pipes[1], true);
                stream_set_timeout($pipes[1], 10);
                $this->assertSame($operation === 'configuration' ? 'configuration_saved' : 'stale_conflict', stream_get_contents($pipes[1]));
                fclose($pipes[1]);
                fclose($pipes[2]);
                $pipes = [];
                $this->assertSame(0, proc_close($process));
                $process = null;
                $this->assertDatabaseCount('request_reviews', 1);
                $this->assertDatabaseHas('request_items', ['request_id' => $id, 'active' => true, 'outcome' => 'outstanding']);
                $this->assertDatabaseHas('requests', ['id' => $id, 'version' => $operation === 'configuration' ? 3 : 2, 'review_state' => $operation === 'configuration' ? 'stale' : 'ready']);
                $this->assertSame($custody, DB::table('custody_current')->orderBy('subject_id')->get()->toJson());
            } finally {
                if (is_resource($process)) {
                    proc_terminate($process);
                    foreach ($pipes as $pipe) {
                        if (is_resource($pipe)) {
                            fclose($pipe);
                        }
                    }proc_close($process);
                }
                while (DB::connection('review_fixture')->transactionLevel() > 0) {
                    DB::connection('review_fixture')->rollBack();
                }
                DB::connection('review_fixture')->statement('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
                DB::setDefaultConnection('pgsql');
                Schema::clearResolvedInstance('db.schema');
                DB::purge('review_fixture');
                if (is_file($file)) {
                    unlink($file);
                } if (is_file($signal)) {
                    unlink($signal);
                }
            }
            $this->assertFalse(DB::table('pg_namespace')->where('nspname', $schema)->exists());
        }
    }
}
