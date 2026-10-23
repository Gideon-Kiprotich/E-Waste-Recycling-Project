<?php

namespace App\Support\Reporting;

use App\Enums\Role;
use App\Models\User;
use App\Support\Collection\Review;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class Exports
{
    public function generate(User $actor, array $input, string $revision, ?string $sessionFingerprint = null): string
    {
        abort_if($actor->role() === Role::Consumer, 403);
        $started = microtime(true);
        $requestAudit = $this->reserveRequest($actor);
        try {
            [$id, $snapshot] = DB::transaction(function () use ($actor, $input, $revision, $sessionFingerprint, $requestAudit, $started) {
                $this->timeBudget($started);
                Review::lock();
                $snapshot = app(Reports::class)->snapshot($actor, $input, $sessionFingerprint);
                abort_unless(hash_equals($snapshot['revision'], $revision), 409, 'Report changed. Refresh before exporting.');
                abort_if(DB::table('report_exports')->where('requester_id', $actor->id)->where('state', 'generating')->exists(), 409, 'An export is already generating.');
                abort_if((($snapshot['summary']['Unclassifiable estimate ancestry'] ?? 0) + ($snapshot['summary']['Unclassifiable admission evidence (excluded)'] ?? 0)) > 0, 409, 'Estimate ancestry needs review before export.');
                $id = Str::uuid()->toString();
                $path = $this->path($id.'.csv');
                abort_if(file_exists($path) || is_link($path) || file_exists($path.'.part') || is_link($path.'.part'), 409, 'Export storage reference is unavailable.');
                DB::table('report_exports')->insert(['id' => $id, 'requester_id' => $actor->id, 'request_audit_id' => $requestAudit, 'kind' => $snapshot['filters']['kind'], 'state' => 'generating', 'filters' => json_encode($snapshot['filters'], JSON_THROW_ON_ERROR), 'revision' => $revision, 'authority' => $snapshot['authority'], 'session_version' => $actor->fresh()->session_version, 'session_fingerprint' => $sessionFingerprint, 'generated_at' => $snapshot['generated_at'], 'expires_at' => CarbonImmutable::parse($snapshot['generated_at'])->addMinutes($snapshot['filters']['kind'] === 'manifest' ? 30 : 1440), 'storage_key' => $id.'.csv', 'temporary_key' => $id.'.csv.part']);

                return [$id, $snapshot];
            });
        } catch (Throwable $error) {
            $this->audit(null, $actor->id, 'failed');
            abort_if($this->limited($error), 422, 'Export generation exceeded its limits. Refresh and retry.');
            throw $error;
        }
        $path = $this->path($id.'.csv');
        $temporary = $path.'.part';
        $createdTemp = false;
        $published = false;
        try {
            [$bytes, $rows] = app(Csv::class)->encode($snapshot, $started);
            $oldMask = umask(0077);
            try {
                $handle = fopen($temporary, 'x');
                if (! $handle) {
                    throw new \RuntimeException('Private export write failed.');
                }
                $createdTemp = true;
                try {
                    if (fwrite($handle, $bytes) !== strlen($bytes) || ! fflush($handle)) {
                        throw new \RuntimeException('Private export write failed.');
                    }
                } finally {
                    fclose($handle);
                }
            } finally {
                umask($oldMask);
            }
            DB::transaction(function () use ($actor, $id, $temporary, $path, $bytes, $rows, $started, $sessionFingerprint, &$published) {
                $this->timeBudget($started);
                Review::lock();
                $row = DB::table('report_exports')->where('id', $id)->lockForUpdate()->firstOrFail();
                abort_unless($row->state === 'generating' && $this->current($actor, $row, $sessionFingerprint), 409, 'Report or authority changed. Refresh before exporting.');
                $this->timeBudget($started);
                if (! link($temporary, $path)) {
                    throw new \RuntimeException('Private export publication failed.');
                }
                $published = true;
                unlink($temporary);
                DB::table('report_exports')->where('id', $id)->update(['state' => 'ready', 'temporary_key' => null, 'checksum' => hash('sha256', $bytes), 'row_count' => $rows, 'byte_count' => strlen($bytes)]);
                $this->audit($id, $actor->id, 'generated');
            });
        } catch (Throwable $error) {
            foreach ([$createdTemp ? $temporary : null, $published ? $path : null] as $owned) {
                if ($owned !== null && is_file($owned) && ! is_link($owned)) {
                    unlink($owned);
                }
            }
            $limited = $this->limited($error);
            DB::transaction(function () use ($id, $actor, $limited) {
                Review::lock();
                DB::table('report_exports')->where('id', $id)->where('state', 'generating')->update(['state' => 'failed', 'failure_code' => $limited ? 'limit_exceeded' : 'generation_failed', 'storage_key' => null, 'temporary_key' => null]);
                $this->audit($id, $actor->id, 'failed');
            });
            abort($limited ? 422 : 409, 'Export failed as a whole. Refresh the report and retry within the limits.');
        }

        return $id;
    }

    private function timeBudget(float $started): void
    {
        $milliseconds = 15000 - (int) ceil((microtime(true) - $started) * 1000);
        if ($milliseconds <= 0) {
            throw new ExportLimit('Export generation exceeded fifteen seconds.');
        }
        DB::select("SELECT set_config('statement_timeout', ?, true)", [$milliseconds.'ms']);
    }

    private function limited(Throwable $error): bool
    {
        return $error instanceof ExportLimit || ($error instanceof \PDOException && $error->getCode() === '57014');
    }

    public function current(User $actor, object $row, ?string $sessionFingerprint = null): bool
    {
        try {
            $actor = User::with('membership')->findOrFail($actor->id);
            if ($row->session_fingerprint !== $sessionFingerprint || ! $actor->canSignIn() || $actor->session_version !== (int) $row->session_version || ! hash_equals($row->authority, app(Reports::class)->authority($actor))) {
                return false;
            }
            $current = app(Reports::class)->snapshot($actor, json_decode($row->filters, true, flags: JSON_THROW_ON_ERROR), $sessionFingerprint);

            return hash_equals($row->revision, $current['revision']);
        } catch (HttpExceptionInterface|ModelNotFoundException) {
            return false;
        }
    }

    public function retrieve(User $actor, string $id, ?string $sessionFingerprint = null): ?string
    {
        return DB::transaction(function () use ($actor, $id, $sessionFingerprint) {
            Review::lock();
            $row = DB::table('report_exports')->where('id', $id)->lockForUpdate()->firstOrFail();
            if ($row->requester_id !== $actor->id) {
                $this->audit($id, $actor->id, 'denied');

                return null;
            }
            if ($row->state !== 'ready') {
                $this->audit($id, $actor->id, 'denied');

                return null;
            }
            $expired = now()->greaterThanOrEqualTo($row->expires_at);
            if ($expired || ! $this->current($actor, $row, $sessionFingerprint)) {
                DB::table('report_exports')->where('id', $id)->update(['state' => $expired ? 'expired' : 'invalidated']);
                $this->audit($id, $actor->id, $expired ? 'expired' : 'invalidated');

                return null;
            }
            $path = $this->path($row->storage_key);
            $bytes = is_file($path) && ! is_link($path) ? file_get_contents($path) : false;
            if ($bytes === false || strlen($bytes) !== (int) $row->byte_count || ! hash_equals($row->checksum, hash('sha256', $bytes))) {
                DB::table('report_exports')->where('id', $id)->update(['state' => 'invalidated']);
                $this->audit($id, $actor->id, 'invalidated');

                return null;
            }
            $this->audit($id, $actor->id, 'download');

            return $bytes;
        });
    }

    public function cleanup(int $limit = 100): int
    {
        $count = 0;
        foreach (DB::table('report_exports')->where(fn ($q) => $q->where('expires_at', '<=', now())->orWhere(fn ($q) => $q->whereNotNull('temporary_key')->where('generated_at', '<=', now()->subSeconds(15))))->where(fn ($q) => $q->whereNotNull('storage_key')->orWhereNotNull('temporary_key'))->orderBy('id')->limit(min(1000, max(1, $limit)))->pluck('id') as $id) {
            DB::transaction(function () use ($id, &$count) {
                Review::lock();
                $row = DB::table('report_exports')->where('id', $id)->lockForUpdate()->firstOrFail();
                $expired = now()->greaterThanOrEqualTo($row->expires_at);
                $abandoned = $row->temporary_key !== null && now()->greaterThanOrEqualTo(CarbonImmutable::parse($row->generated_at)->addSeconds(15));
                if ((! $expired && ! $abandoned) || ($row->storage_key === null && $row->temporary_key === null)) {
                    return;
                }
                foreach ([$row->storage_key ? $this->path($row->storage_key) : null, $row->temporary_key ? $this->path($row->id.'.csv').'.part' : null] as $path) {
                    if ($path !== null && is_file($path) && ! is_link($path) && ! unlink($path)) {
                        return;
                    }
                }
                $state = $row->state === 'generating' && $abandoned ? 'failed' : (in_array($row->state, ['ready', 'generating'], true) ? 'expired' : $row->state);
                DB::table('report_exports')->where('id', $id)->update(['state' => $state, 'failure_code' => $state === 'failed' ? 'generation_failed' : $row->failure_code, 'storage_key' => null, 'temporary_key' => null]);
                if ($state !== $row->state && in_array($state, ['expired', 'failed'], true)) {
                    $this->audit($id, $row->requester_id, $state);
                }
                $this->audit($id, $row->requester_id, 'cleaned');
                $count++;
            });
        }

        return $count;
    }

    public function path(string $key): string
    {
        if (! preg_match('/\A[0-9a-f-]{36}\.csv\z/', $key)) {
            throw new \RuntimeException('Invalid owned export key.');
        }
        $root = storage_path('report-exports');
        if (is_link($root)) {
            throw new \RuntimeException('Private export root cannot be a symlink.');
        }
        if (! is_dir($root) && ! mkdir($root, 0700)) {
            throw new \RuntimeException('Private export storage is unavailable.');
        }
        if ((fileperms($root) & 0077) !== 0) {
            throw new \RuntimeException('Export storage must remain private.');
        }

        return $root.'/'.$key;
    }

    private function reserveRequest(User $actor): string
    {
        $id = DB::transaction(function () use ($actor) {
            Review::lock();
            $current = User::with('membership')->findOrFail($actor->id);
            abort_unless($current->canSignIn() && $current->session_version === $actor->session_version, 403);
            if (DB::table('report_export_accesses')->where('actor_id', $actor->id)->where('outcome', 'requested')->where('recorded_at', '>', now()->subHour())->count() >= 5) {
                $this->audit(null, $actor->id, 'denied');

                return null;
            }

            return $this->audit(null, $actor->id, 'requested');
        });
        abort_if($id === null, 429, 'Five export requests per hour are allowed.');

        return $id;
    }

    private function audit(?string $id, string $actor, string $outcome): string
    {
        $audit = Str::uuid()->toString();
        DB::table('report_export_accesses')->insert(['id' => $audit, 'export_id' => $id, 'actor_id' => $actor, 'outcome' => $outcome, 'recorded_at' => now()]);

        return $audit;
    }
}
