<?php

use App\Actions\ManageDirectory;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

require dirname(__DIR__, 2).'/vendor/autoload.php';
putenv('APP_ENV=testing');
umask(0077);
final class DirectoryRaceBootstrap extends TestCase {}
try {
    (new DirectoryRaceBootstrap('race'))->createApplication();
    $fixture = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
    if (! preg_match('/\Adirectory_race_[a-f0-9]{32}\z/', $fixture['schema'])) {
        throw new RuntimeException('Invalid fixture.');
    }
    $c = config('database.connections.pgsql');
    $c['search_path'] = $fixture['schema'];
    config(['database.connections.pgsql' => $c]);
    DB::purge('pgsql');
    $identity = DB::selectOne('SELECT current_database() AS database,current_schema() AS schema,pg_backend_pid() AS pid');
    if ($identity->database !== 'ewaste_testing' || $identity->schema !== $fixture['schema']) {
        throw new RuntimeException('Unsafe fixture.');
    }
    file_put_contents($fixture['signal'], (string) $identity->pid);
    try {
        if ($fixture['direct']) {
            DB::table('organizations')->insert($fixture['fields']);
        } else {
            app(ManageDirectory::class)->save(User::findOrFail($fixture['actor']), $fixture['kind'], $fixture['id'], $fixture['fields']);
        }
        echo 'unexpected_success';
    } catch (ValidationException $e) {
        if (! isset($e->errors()['name'])) {
            throw $e;
        }
        echo 'duplicate_rejected';
    } catch (QueryException $e) {
        if ($e->getCode() !== '23505' || ! str_contains($e->getMessage(), 'organizations_name_identity')) {
            throw $e;
        }
        echo 'duplicate_rejected';
    }
} catch (Throwable) {
    echo 'worker_failed';
    exit(1);
}
