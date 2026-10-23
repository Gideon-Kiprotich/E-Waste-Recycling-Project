<?php

use App\Models\User;
use App\Support\Notices\Delivery;
use App\Support\Notices\Milestones;
use App\Support\Reporting\Csv;
use App\Support\Reporting\Exports;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

require dirname(__DIR__, 2).'/vendor/autoload.php';
putenv('APP_ENV=testing');
umask(0077);
final class ReportingRaceBootstrap extends TestCase {}
try {
    (new ReportingRaceBootstrap('race'))->createApplication();
    $f = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
    if (! preg_match('/\Areporting_test_[a-f0-9]{32}\z/', $f['schema'])) {
        throw new RuntimeException('Unsafe schema');
    }
    $c = config('database.connections.pgsql');
    $c['search_path'] = $f['schema'];
    config(['database.connections.pgsql' => $c]);
    DB::purge('pgsql');
    $identity = DB::selectOne('SELECT current_database() db,current_user role,current_schema() schema,pg_backend_pid() pid');
    if ($identity->db !== 'ewaste_testing' || $identity->role !== 'ewaste_app' || $identity->schema !== $f['schema']) {
        throw new RuntimeException('Unsafe connection');
    }
    if ($f['mode'] === 'delivery_pause') {
        app()->instance(Delivery::class, new class($f['signal'], $identity->pid) extends Delivery
        {
            public function __construct(private string $signal, private int $pid) {}

            public function run(Builder $query, string $alias, callable $insert, int $limit = 100, ?callable $authorized = null): int
            {
                return parent::run($query, $alias, function ($row) use ($insert) {
                    $insert($row);
                    file_put_contents($this->signal, (string) $this->pid);
                    $end = microtime(true) + 12;
                    while (! is_file($this->signal.'.continue') && microtime(true) < $end) {
                        usleep(10000);
                    }
                    if (! is_file($this->signal.'.continue')) {
                        throw new RuntimeException('Race release missing');
                    }
                }, 1, $authorized);
            }
        });
        echo app(Milestones::class)->deliver(1);
    } elseif ($f['mode'] === 'generate') {
        app()->instance(Csv::class, new class($f['signal'], $identity->pid) extends Csv
        {
            public function __construct(private string $signal, private int $pid) {}

            public function encode(array $snapshot, float $started): array
            {
                file_put_contents($this->signal, (string) $this->pid);
                $end = microtime(true) + 12;
                while (! is_file($this->signal.'.continue') && microtime(true) < $end) {
                    usleep(10000);
                }
                if (! is_file($this->signal.'.continue')) {
                    throw new RuntimeException('Race release missing');
                }

                return parent::encode($snapshot, $started);
            }
        });
        try {
            app(Exports::class)->generate(User::findOrFail($f['actor']), $f['filters'], $f['revision']);
            echo 'published';
        } catch (HttpException $error) {
            if ($error->getStatusCode() !== 409) {
                throw $error;
            }echo 'stale_rejected';
        }
    } else {
        file_put_contents($f['signal'], (string) $identity->pid);
        if ($f['mode'] === 'deliver') {
            echo app(Milestones::class)->deliver();
        } elseif ($f['mode'] === 'revoke') {
            DB::table('memberships')->where('user_id', $f['actor'])->update(['active' => false]);
            echo 'revoked';
        } elseif ($f['mode'] === 'retrieve') {
            echo app(Exports::class)->retrieve(User::findOrFail($f['actor']), $f['export']) === null ? 'denied' : 'bytes_authorized';
        } else {
            throw new RuntimeException('Unknown mode');
        }
    }
} catch (Throwable) {
    echo 'worker_failed';
    exit(1);
}
