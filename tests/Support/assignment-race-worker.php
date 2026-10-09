<?php

use App\Actions\AllocateCollector;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

require dirname(__DIR__, 2).'/vendor/autoload.php';
putenv('APP_ENV=testing');
umask(0077);
final class AssignmentRaceBootstrap extends TestCase {}
try {
    (new AssignmentRaceBootstrap('race'))->createApplication();
    $fixture = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
    if (! preg_match('/\Aassignment_race_[a-f0-9]{32}\z/', $fixture['schema'])) {
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
        if ($fixture['operation'] === 'direct') {
            DB::table('assignments')->insert($fixture['fields']);
        } elseif ($fixture['operation'] === 'accept') {
            app(AllocateCollector::class)->respond(User::findOrFail($fixture['actor']), $fixture['id'], $fixture['fields']);
        } else {
            app(AllocateCollector::class)->allocate(User::findOrFail($fixture['actor']), $fixture['id'], $fixture['fields']);
        }
        echo 'unexpected_success';
    } catch (HttpException $e) {
        if ($e->getStatusCode() !== 409) {
            throw $e;
        }
        echo 'conflict_rejected';
    } catch (QueryException $e) {
        if ($e->getCode() !== '23505' || ! str_contains($e->errorInfo[2] ?? '', 'assignments_active_request_unique')) {
            throw $e;
        }
        echo 'conflict_rejected';
    }
} catch (Throwable) {
    echo 'worker_failed';
    exit(1);
}
