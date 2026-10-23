<?php

namespace Tests\Feature;

use App\Actions\RecordVisit;
use App\Http\Controllers\ReportingController;
use App\Models\User;
use App\Support\Notices\Milestones;
use App\Support\Reporting\Exports;
use App\Support\Reporting\Reports;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\DownstreamFixtures;
use Tests\Support\ReportingSchema;
use Tests\TestCase;

class ReportingConcurrencyTest extends TestCase
{
    use DownstreamFixtures,ReportingSchema;

    private function start(array $fields): array
    {
        $path = tempnam(sys_get_temp_dir(), 'ewaste-reporting-race-');
        chmod($path, 0600);
        $fields += ['schema' => DB::selectOne('SELECT current_schema() schema')->schema, 'signal' => $path.'.ready'];
        file_put_contents($path, json_encode($fields, JSON_THROW_ON_ERROR));
        $process = proc_open([PHP_BINARY, base_path('tests/Support/reporting-race-worker.php'), $path], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $end = microtime(true) + 12;
        while (! is_file($path.'.ready') && microtime(true) < $end) {
            usleep(10000);
        }
        $this->assertFileExists($path.'.ready');

        return [$path, $process, $pipes];
    }

    private function finish(array $work): string
    {
        [$path,$process,$pipes] = $work;
        try {
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process));
            $this->assertSame('', $error);

            return $output;
        } finally {
            foreach ([$path, $path.'.ready', $path.'.ready.continue'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    private function waiting(array $work): void
    {
        $pid = (int) file_get_contents($work[0].'.ready');
        $end = microtime(true) + 8;
        $waiting = false;
        do {
            $waiting = DB::connection('pgsql')->selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid=?', [$pid])?->wait_event_type === 'Lock';
            if ($waiting) {
                break;
            }usleep(10000);
        } while (microtime(true) < $end);
        $this->assertTrue($waiting, 'A separate native process must wait on the held PostgreSQL gate.');
    }

    public function test_two_workers_skip_locked_and_crash_rollback_has_one_eventual_notice(): void
    {
        $owner = User::factory()->create();
        $this->device($owner);
        $this->device($owner);
        DB::commit();
        $id = DB::table('notification_outbox')->orderBy('id')->value('id');
        DB::beginTransaction();
        $row = DB::table('notification_outbox')->where('id', $id)->lockForUpdate()->first();
        DB::table('milestone_notifications')->insert(['id' => (string) Str::uuid(), 'outbox_id' => $id, 'event_id' => $row->event_id, 'recipient_id' => $owner->id]);
        DB::table('notification_outbox')->where('id', $id)->update(['state' => 'delivered']);
        $worker = $this->start(['mode' => 'deliver']);
        $this->assertSame('1', $this->finish($worker));
        DB::rollBack();
        $this->assertSame(1, app(Milestones::class)->deliver());
        $this->assertSame(0, app(Milestones::class)->deliver());
        $this->assertSame(2, DB::table('milestone_notifications')->count());
        $this->assertSame(2, DB::table('notification_outbox')->where('state', 'delivered')->count());
    }

    public function test_recipient_scope_change_after_inbox_insert_is_suppressed_at_real_worker_commit(): void
    {
        $owner = User::factory()->create();
        $this->device($owner);
        DB::commit();
        $worker = $this->start(['mode' => 'delivery_pause']);
        DB::table('memberships')->where('user_id', $owner->id)->update(['version' => DB::raw('version+1')]);
        file_put_contents($worker[0].'.ready.continue', 'continue');
        $this->assertSame('0', $this->finish($worker));
        $this->assertSame(0, DB::table('milestone_notifications')->count());
        $row = DB::table('notification_outbox')->firstOrFail();
        $this->assertSame('suppressed', $row->state);
        $this->assertSame('authority_revoked', $row->delivery_error);
    }

    public function test_generation_publication_rechecks_a_real_measurement_correction_and_discards_owned_file(): void
    {
        $w = $this->downstreamWork();
        DB::commit();
        $snapshot = app(Reports::class)->snapshot($w['receiver'], ['kind' => 'batches']);
        $worker = $this->start(['mode' => 'generate', 'actor' => $w['receiver']->id, 'filters' => $snapshot['filters'], 'revision' => $snapshot['revision']]);
        $this->batchRecord($w, 'measurement', ['target_id' => $w['batch'], 'previous_measurement_id' => DB::table('dismantling_batches')->where('subject_id', $w['batch'])->value('input_measurement_id'), 'net_kg' => '9.900']);
        file_put_contents($worker[0].'.ready.continue', 'continue');
        $this->assertSame('stale_rejected', $this->finish($worker));
        $row = DB::table('report_exports')->firstOrFail();
        $this->assertSame('invalidated', $row->state);
        $this->assertFileDoesNotExist(app(Exports::class)->path($row->id.'.csv'));
        $this->assertFileDoesNotExist(app(Exports::class)->path($row->id.'.csv').'.part');
    }

    public function test_download_gate_spans_first_byte_and_revoke_then_restore_never_revives_file(): void
    {
        $w = $this->downstreamWork();
        DB::commit();
        $snapshot = app(Reports::class)->snapshot($w['receiver'], ['kind' => 'batches']);
        $id = app(Exports::class)->generate($w['receiver'], $snapshot['filters'], $snapshot['revision']);
        try {
            $request = Request::create('/reports/exports/'.$id);
            $request->setUserResolver(fn () => $w['receiver']);
            $response = app(ReportingController::class)->download($request, $id, app(Exports::class));
            $worker = $this->start(['mode' => 'revoke', 'actor' => $w['receiver']->id]);
            $this->waiting($worker);
            ob_start();
            $response->sendContent();
            $bytes = ob_get_clean();
            $this->assertStringContainsString($snapshot['revision'], $bytes);
            $this->assertSame('revoked', $this->finish($worker));
            DB::table('memberships')->where('user_id', $w['receiver']->id)->update(['active' => true]);
            $this->assertNull(app(Exports::class)->retrieve($w['receiver'], $id));
            $this->assertSame('invalidated', DB::table('report_exports')->where('id', $id)->value('state'));
        } finally {
            @unlink(app(Exports::class)->path($id.'.csv'));
        }
    }

    public function test_manifest_generation_rechecks_real_receipt_consumption_before_publication(): void
    {
        [$admin,$owner,$collector,$route,$ordered] = $this->pickupWork(1);
        DB::commit();
        $snapshot = app(Reports::class)->snapshot($collector, []);
        $worker = $this->start(['mode' => 'generate', 'actor' => $collector->id, 'filters' => $snapshot['filters'], 'revision' => $snapshot['revision']]);
        $request = DB::table('assignments')->where('id', $ordered[0])->value('request_id');
        app(RecordVisit::class)->record($collector, $request, $this->visitFields($request));
        file_put_contents($worker[0].'.ready.continue', 'continue');
        $this->assertSame('stale_rejected', $this->finish($worker));
        $row = DB::table('report_exports')->firstOrFail();
        $this->assertSame('invalidated', $row->state);
        $this->assertFileDoesNotExist(app(Exports::class)->path($row->id.'.csv'));
    }

    public function test_failed_publication_root_commit_compensates_only_its_new_private_file(): void
    {
        $w = $this->downstreamWork();
        DB::commit();
        $snapshot = app(Reports::class)->snapshot($w['receiver'], ['kind' => 'batches']);
        DB::unprepared("CREATE FUNCTION reporting_forced_commit_failure() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.state='ready' THEN RAISE EXCEPTION 'Injected publication COMMIT failure'; END IF; RETURN NULL; END; \$\$; CREATE CONSTRAINT TRIGGER forced_export_commit AFTER UPDATE ON report_exports DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION reporting_forced_commit_failure()");
        try {
            app(Exports::class)->generate($w['receiver'], $snapshot['filters'], $snapshot['revision']);
            $this->fail('Injected COMMIT failure was ignored.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $row = DB::table('report_exports')->firstOrFail();
        $this->assertSame('failed', $row->state);
        $this->assertFileDoesNotExist(app(Exports::class)->path($row->id.'.csv'));
        $this->assertFileDoesNotExist(app(Exports::class)->path($row->id.'.csv').'.part');
        $this->assertSame(0, DB::table('report_export_accesses')->where('outcome', 'generated')->count());
        $this->assertSame(1, DB::table('report_export_accesses')->where('outcome', 'failed')->count());
    }

    public function test_root_commit_rejects_delivery_without_persisted_inbox(): void
    {
        $owner = User::factory()->create();
        $this->device($owner);
        DB::commit();
        $id = DB::table('notification_outbox')->value('id');
        try {
            DB::transaction(fn () => DB::table('notification_outbox')->where('id', $id)->update(['state' => 'delivered']));
            $this->fail('Root COMMIT accepted a missing inbox.');
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('pending', DB::table('notification_outbox')->where('id', $id)->value('state'));
        $this->assertSame(1, app(Milestones::class)->deliver());
    }
}
