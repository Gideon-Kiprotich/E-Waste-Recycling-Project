<?php

use App\Actions\RecordSanitization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

require dirname(__DIR__, 2).'/vendor/autoload.php';
putenv('APP_ENV=testing');
umask(0077);
final class SanitizationRaceBootstrap extends TestCase {}
try {
    (new SanitizationRaceBootstrap('race'))->createApplication();
    $f = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
    if (! preg_match('/\Asanitization_race_[a-f0-9]{32}\z/', $f['schema'])) {
        throw new RuntimeException('Invalid fixture');
    }
    $c = config('database.connections.pgsql');
    $c['search_path'] = $f['schema'];
    config(['database.connections.pgsql' => $c]);
    DB::purge('pgsql');
    $id = DB::selectOne('SELECT current_database() AS db,current_schema() AS schema,pg_backend_pid() AS pid');
    if ($id->db !== 'ewaste_testing' || $id->schema !== $f['schema']) {
        throw new RuntimeException('Unsafe fixture');
    }
    file_put_contents($f['signal'], (string) $id->pid);
    try {
        $id = app(RecordSanitization::class)->record(User::findOrFail($f['actor']), $f['subject'], $f['kind'], $f['fields']);
        if (! $f['retry'] || $id !== $f['winner']) {
            echo 'unexpected_success';
            exit(1);
        }
        echo 'original_returned';
    } catch (HttpException $e) {
        if ($e->getStatusCode() !== 409) {
            throw $e;
        }
        echo 'conflict_rejected';
    }
} catch (Throwable) {
    echo 'worker_failed';
    exit(1);
}
