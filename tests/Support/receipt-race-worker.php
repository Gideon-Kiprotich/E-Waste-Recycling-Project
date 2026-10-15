<?php

use App\Actions\CollectionRequests;
use App\Actions\RecordVisit;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

require dirname(__DIR__, 2).'/vendor/autoload.php';
putenv('APP_ENV=testing');
umask(0077);
final class ReceiptRaceBootstrap extends TestCase {}
try {
    (new ReceiptRaceBootstrap('race'))->createApplication();
    $f = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
    if (! preg_match('/\Areceipt_race_[a-f0-9]{32}\z/', $f['schema'])) {
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
        if ($f['operation'] === 'cancel') {
            app(CollectionRequests::class)->change(User::findOrFail($f['actor']), $f['request'], $f['fields']);
        } elseif ($f['operation'] === 'direct') {
            DB::table('first_receipts')->insert($f['receipt']);
        } else {
            app(RecordVisit::class)->record(User::findOrFail($f['actor']), $f['request'], $f['fields']);
        }
        echo 'unexpected_success';
        exit(1);
    } catch (HttpException $e) {
        if ($e->getStatusCode() !== 409) {
            throw $e;
        }echo 'conflict_rejected';
    } catch (QueryException $e) {
        if ($e->getCode() !== '23505' || ! str_contains($e->errorInfo[2] ?? '', 'first_receipts_pkey')) {
            throw $e;
        }echo 'conflict_rejected';
    }
} catch (Throwable) {
    echo 'worker_failed';
    exit(1);
}
