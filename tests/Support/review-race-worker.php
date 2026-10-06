<?php

use App\Actions\ManageDirectory;
use App\Actions\ReviewRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

require dirname(__DIR__, 2).'/vendor/autoload.php';
putenv('APP_ENV=testing');
umask(0077);
final class ReviewRaceBootstrap extends TestCase {}
try {
    (new ReviewRaceBootstrap('race'))->createApplication();
    $fixture = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
    if (! preg_match('/\Areview_fixture_[a-f0-9]{32}\z/', $fixture['schema'])) {
        throw new RuntimeException('Invalid fixture namespace.');
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
    $actor = User::findOrFail($fixture['actor']);
    try {
        if ($fixture['operation'] === 'configuration') {
            app(ManageDirectory::class)->save($actor, 'organization', $fixture['id'], $fixture['fields']);
            echo 'configuration_saved';
        } else {
            app(ReviewRequest::class)->decide($actor, $fixture['id'], $fixture['fields']);
            echo 'unexpected_success';
        }
    } catch (HttpException $e) {
        if ($e->getStatusCode() !== 409) {
            throw $e;
        }
        echo 'stale_conflict';
    }
} catch (Throwable) {
    echo 'worker_failed';
    exit(1);
}
