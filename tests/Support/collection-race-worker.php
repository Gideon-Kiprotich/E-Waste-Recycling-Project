<?php

use App\Actions\CollectionRequests;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

// Launched only by the guarded PostgreSQL test, with a private synthetic fixture file.
require dirname(__DIR__, 2).'/vendor/autoload.php';
putenv('APP_ENV=testing');
umask(0077);
final class CollectionRaceBootstrap extends TestCase {}
try {
    $app = (new CollectionRaceBootstrap('race'))->createApplication();
    $fixture = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
    if (! preg_match('/\Acollection_fixture_[a-f0-9]{32}\z/', $fixture['schema'])) {
        throw new RuntimeException('Invalid fixture namespace.');
    }
    $configuration = config('database.connections.pgsql');
    $configuration['search_path'] = $fixture['schema'];
    config(['database.connections.pgsql' => $configuration]);
    DB::purge('pgsql');
    $identity = DB::selectOne('SELECT current_database() AS database,current_schema() AS schema,pg_backend_pid() AS pid');
    if ($identity->database !== 'ewaste_testing' || $identity->schema !== $fixture['schema']) {
        throw new RuntimeException('Unsafe fixture connection.');
    }
    file_put_contents($fixture['signal'], (string) $identity->pid);
    $owner = User::findOrFail($fixture['owner']);
    try {
        app(CollectionRequests::class)->submit($owner, $fixture['fields']);
        echo 'success';
    } catch (ValidationException $e) {
        echo isset($e->errors()['items']) ? 'reservation_rejected' : 'unexpected_validation';
    }
} catch (Throwable) {
    echo 'worker_failed';
    exit(1);
}
