<?php

use App\Actions\AcceptDownstreamResponsibility;
use App\Actions\MoveBatchOutputs;
use App\Actions\ReviewRestrictions;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

require dirname(__DIR__, 2).'/vendor/autoload.php';
putenv('APP_ENV=testing');
umask(0077);
final class DownstreamCorrectionRaceBootstrap extends TestCase {}
try {
    (new DownstreamCorrectionRaceBootstrap('race'))->createApplication();
    $f = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
    if (! preg_match('/\Adownstream_correction_race_[a-f0-9]{32}\z/', $f['schema'])) {
        throw new RuntimeException('Unsafe fixture');
    }
    $config = config('database.connections.pgsql');
    $config['search_path'] = $f['schema'];
    config(['database.connections.pgsql' => $config]);
    DB::purge('pgsql');
    $identity = DB::selectOne('SELECT current_database() AS db,current_schema() AS schema,current_user AS role,pg_backend_pid() AS pid');
    if ($identity->db !== 'ewaste_testing' || $identity->role !== 'ewaste_app' || $identity->schema !== $f['schema']) {
        throw new RuntimeException('Unsafe fixture');
    }
    file_put_contents($f['signal'], (string) $identity->pid);
    try {
        $actor = User::findOrFail($f['actor']);
        $id = match ($f['action']) {
            'recipient' => app(AcceptDownstreamResponsibility::class)->record($actor, $f['shipment'], 'accepted', $f['fields']),
            'reserve', 'accept', 'dispatch', 'complete', 'source_loss' => app(MoveBatchOutputs::class)->record($actor, $f['batch'], $f['action'], $f['fields']),
            default => app(ReviewRestrictions::class)->record($actor, $f['case'], 'approve', $f['fields']),
        };
        if (! $f['retry'] || $id !== $f['winner']) {
            echo 'unexpected_success';
            exit(1);
        }
        echo 'original_returned';
    } catch (HttpException $e) {
        if (! in_array($e->getStatusCode(), [403, 404, 409], true)) {
            throw $e;
        }
        echo 'conflict_rejected';
    } catch (ValidationException) {
        echo 'conflict_rejected';
    }
} catch (Throwable) {
    echo 'worker_failed';
    exit(1);
}
