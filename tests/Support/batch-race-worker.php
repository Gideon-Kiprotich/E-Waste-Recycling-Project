<?php

use App\Actions\DismantleBatches;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

require dirname(__DIR__, 2).'/vendor/autoload.php';
putenv('APP_ENV=testing');
umask(0077);
final class BatchRaceBootstrap extends TestCase {}
try {
    (new BatchRaceBootstrap('race'))->createApplication();
    $f = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
    if (! preg_match('/\Abatch_race_[a-f0-9]{32}\z/', $f['schema'])) {
        throw new RuntimeException('Unsafe schema');
    }
    $c = config('database.connections.pgsql');
    $c['search_path'] = $f['schema'];
    config(['database.connections.pgsql' => $c]);
    DB::purge('pgsql');
    $identity = DB::selectOne('SELECT current_database() AS db,current_schema() AS schema,current_user AS role,pg_backend_pid() AS pid');
    if ($identity->db !== 'ewaste_testing' || $identity->schema !== $f['schema'] || $identity->role !== 'ewaste_app') {
        throw new RuntimeException('Unsafe fixture');
    }
    file_put_contents($f['signal'], (string) $identity->pid);
    try {
        $id = app(DismantleBatches::class)->record(User::findOrFail($f['actor']), $f['batch'], $f['kind'], $f['fields']);
        if (! $f['retry'] || $id !== $f['winner']) {
            echo 'unexpected_success';
            exit(1);
        }echo 'original_returned';
    } catch (HttpException $e) {
        if (! in_array($e->getStatusCode(), [403, 404, 409], true)) {
            throw $e;
        }echo 'conflict_rejected';
    } catch (ValidationException) {
        echo 'conflict_rejected';
    }
} catch (Throwable) {
    echo 'worker_failed';
    exit(1);
}
