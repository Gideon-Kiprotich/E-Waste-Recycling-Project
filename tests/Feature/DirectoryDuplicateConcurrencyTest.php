<?php

namespace Tests\Feature;

use App\Actions\ManageDirectory;
use App\Enums\Role;
use App\Models\User;
use App\Support\Collection\SiteLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\CollectionFixtures;
use Tests\TestCase;

class DirectoryDuplicateConcurrencyTest extends TestCase
{
    use CollectionFixtures;

    public function test_equivalent_organization_and_place_create_edit_races_and_direct_insert_cannot_commit_duplicates(): void
    {
        config(['hashing.argon.memory' => 1024, 'hashing.argon.time' => 1]);
        foreach (['organization_create', 'organization_edit', 'place_create', 'place_edit', 'direct_insert'] as $operation) {
            $schema = 'directory_race_'.str_replace('-', '', Str::uuid()->toString());
            $c = config('database.connections.pgsql');
            $this->assertSame('ewaste_testing', $c['database']);
            $c['search_path'] = $schema;
            config(['database.connections.directory_race' => $c]);
            DB::setDefaultConnection('directory_race');
            Schema::clearResolvedInstance('db.schema');
            $file = tempnam(sys_get_temp_dir(), 'ewaste-directory-race-');
            chmod($file, 0600);
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
                $admins = [];
                for ($i = 0; $i < 2; $i++) {
                    $admin = User::factory()->create();
                    $admin->membership()->update(['role' => Role::Administrator]);
                    $admins[] = $admin->refresh();
                }
                $base = ['name' => 'Synthetic uniqueness target', 'kind' => 'collector', 'review_status' => 'pending', 'contact' => 'Synthetic race', 'reason' => 'Synthetic race', 'synthetic' => '1', 'idempotency_key' => Str::uuid()->toString(), 'duplicate_review_ack' => '1'];
                $kind = str_starts_with($operation, 'place') ? 'place' : 'organization';
                $id = $otherId = null;
                if ($kind === 'place') {
                    $org = DB::table('organizations')->first();
                    $base = array_replace($base, ['kind' => 'collection_point', 'organization_id' => $org->id, 'latitude' => '-1.290000', 'longitude' => '36.830000', 'hours' => 'Unknown; synthetic', 'active' => '0', 'capabilities' => ['laptop:']]);
                }
                if (str_ends_with($operation, '_edit')) {
                    $a = array_replace($base, ['name' => 'Existing distinct fixture A']);
                    $b = array_replace($base, ['name' => 'Existing distinct fixture B', 'idempotency_key' => Str::uuid()->toString()]);
                    if ($kind === 'place') {
                        $a['site_confirmation'] = SiteLocation::token($admins[0]->id, null, $a);
                        $b['site_confirmation'] = SiteLocation::token($admins[0]->id, null, $b);
                    }
                    $id = app(ManageDirectory::class)->save($admins[0], $kind, null, $a);
                    $otherId = app(ManageDirectory::class)->save($admins[0], $kind, null, $b);
                    unset($base['synthetic'], $base['organization_id']);
                    $base['expected_version'] = 1;
                }
                $other = array_replace($base, ['name' => '  SYNTHETIC   UNIQUENESS TARGET ', 'idempotency_key' => Str::uuid()->toString()]);
                if ($kind === 'place') {
                    $other['kind'] = 'depot';
                    $base['site_confirmation'] = SiteLocation::token($admins[0]->id, $id, $base);
                    $other['site_confirmation'] = SiteLocation::token($admins[1]->id, $otherId, $other);
                }
                $raw = null;
                if ($operation === 'direct_insert') {
                    $raw = (array) DB::table('organizations')->first();
                    $raw['name'] = $base['name'];
                    $raw['id'] = Str::uuid()->toString();
                    $raw['code'] = 'synthetic-direct-1';
                    $other = array_replace($raw, ['name' => $other['name'], 'id' => Str::uuid()->toString(), 'code' => 'synthetic-direct-2']);
                }
                $signal = $file.'.ready';
                file_put_contents($file, json_encode(['schema' => $schema, 'actor' => $admins[1]->id, 'kind' => $kind, 'id' => $otherId, 'fields' => $other, 'direct' => $operation === 'direct_insert', 'signal' => $signal], JSON_THROW_ON_ERROR));
                $custody = DB::table('custody_current')->get()->toJson();
                DB::beginTransaction();
                if ($raw) {
                    DB::table('organizations')->insert($raw);
                } else {
                    app(ManageDirectory::class)->save($admins[0], $kind, $id, $base);
                }
                $process = proc_open([PHP_BINARY, base_path('tests/Support/directory-race-worker.php'), $file], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                fclose($pipes[0]);
                stream_set_blocking($pipes[1], false);
                $waiting = false;
                $deadline = microtime(true) + 10;
                do {
                    if (is_file($signal)) {
                        $state = DB::connection('pgsql')->selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid=?', [(int) file_get_contents($signal)]);
                        $waiting = $state?->wait_event_type === 'Lock';
                    }
                    if ($waiting) {
                        break;
                    }
                    usleep(10000);
                } while (microtime(true) < $deadline);
                $this->assertTrue($waiting, $operation.' must reach a real database lock.');
                $this->assertSame('', stream_get_contents($pipes[1]));
                DB::commit();
                stream_set_blocking($pipes[1], true);
                stream_set_timeout($pipes[1], 10);
                $this->assertSame('duplicate_rejected', stream_get_contents($pipes[1]));
                fclose($pipes[1]);
                fclose($pipes[2]);
                $pipes = [];
                $this->assertSame(0, proc_close($process));
                $process = null;
                $this->assertSame(1, DB::table($kind === 'place' ? 'places' : 'organizations')->whereRaw('directory_name_key(name)=directory_name_key(?)', [$base['name']])->count());
                $this->assertSame($custody, DB::table('custody_current')->get()->toJson());
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            } finally {
                if (is_resource($process)) {
                    proc_terminate($process);
                    foreach ($pipes as $pipe) {
                        if (is_resource($pipe)) {
                            fclose($pipe);
                        }
                    }
                    proc_close($process);
                }
                while (DB::connection('directory_race')->transactionLevel() > 0) {
                    DB::connection('directory_race')->rollBack();
                }
                DB::connection('directory_race')->statement('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
                DB::setDefaultConnection('pgsql');
                Schema::clearResolvedInstance('db.schema');
                DB::purge('directory_race');
                if (is_file($file)) {
                    unlink($file);
                }
                if (is_file($file.'.ready')) {
                    unlink($file.'.ready');
                }
            }
            $this->assertFalse(DB::table('pg_namespace')->where('nspname', $schema)->exists());
        }
    }
}
