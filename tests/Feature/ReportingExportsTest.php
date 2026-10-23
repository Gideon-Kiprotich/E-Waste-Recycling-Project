<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Support\Reporting\Csv;
use App\Support\Reporting\ExportLimit;
use App\Support\Reporting\Exports;
use App\Support\Reporting\Reports;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\ReportingSchema;
use Tests\TestCase;

class ReportingExportsTest extends TestCase
{
    use ReportingSchema { tearDown as schemaTearDown; }

    protected function tearDown(): void
    {
        foreach (DB::table('report_exports')->pluck('id') as $id) {
            foreach ([app(Exports::class)->path($id.'.csv'), app(Exports::class)->path($id.'.csv').'.part'] as $path) {
                if (is_file($path) && ! is_link($path)) {
                    unlink($path);
                }
            }
        }
        $this->schemaTearDown();
    }

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->membership()->update(['role' => Role::Administrator]);

        return $u->refresh();
    }

    private function generate(User $u): string
    {
        $snapshot = app(Reports::class)->snapshot($u, ['kind' => 'collections']);

        return app(Exports::class)->generate($u, $snapshot['filters'], $snapshot['revision']);
    }

    public function test_requester_only_bytes_and_irreversible_scope_revocation_even_if_restored_before_download(): void
    {
        $admin = $this->admin();
        $other = User::factory()->create();
        $id = $this->generate($admin);
        $this->assertNotNull(app(Exports::class)->retrieve($admin, $id));
        $this->assertNull(app(Exports::class)->retrieve($other, $id));
        $admin->membership()->update(['active' => false]);
        $admin->membership()->update(['active' => true]);
        $this->assertSame('invalidated', DB::table('report_exports')->where('id', $id)->value('state'));
        $this->assertNull(app(Exports::class)->retrieve($admin, $id));
        $this->assertTrue(DB::table('report_export_accesses')->where('export_id', $id)->where('outcome', 'invalidated')->exists());
        $this->actingAs($admin)->withSession(['auth_session_version' => $admin->session_version])->get(route('reports.download', $id))->assertNotFound();
    }

    public function test_expiry_denies_immediately_and_cleanup_removes_only_owned_expired_bytes(): void
    {
        $admin = $this->admin();
        $id = $this->generate($admin);
        $path = app(Exports::class)->path($id.'.csv');
        $unowned = dirname($path).'/unowned-retained-fixture.txt';
        file_put_contents($unowned, 'Preserve this unowned artifact');
        try {
            $this->travel(24)->hours();
            $this->assertNull(app(Exports::class)->retrieve($admin, $id));
            $this->assertSame('expired', DB::table('report_exports')->where('id', $id)->value('state'));
            $this->assertFileExists($path);
            $this->assertSame(1, app(Exports::class)->cleanup());
            $this->assertFileDoesNotExist($path);
            $this->assertFileExists($unowned);
            $this->assertNull(DB::table('report_exports')->where('id', $id)->value('storage_key'));
            $this->assertSame(1, DB::table('report_exports')->count());
            $this->assertTrue(DB::table('report_export_accesses')->where('outcome', 'cleaned')->exists());
        } finally {
            $this->travelBack();
            unlink($unowned);
        }
    }

    public function test_five_requests_per_hour_and_one_generation_and_stale_snapshot_are_enforced(): void
    {
        $admin = $this->admin();
        for ($n = 0; $n < 5; $n++) {
            $this->generate($admin);
        }
        $snapshot = app(Reports::class)->snapshot($admin, ['kind' => 'collections']);
        $this->actingAs($admin)->withSession(['auth_session_version' => $admin->session_version])->post(route('reports.export'), $snapshot['filters'] + ['revision' => $snapshot['revision']])->assertStatus(429);
        $this->travel(61)->minutes();
        $id = $this->generate($admin);
        $this->assertNotNull(app(Exports::class)->retrieve($admin, $id));
        $old = $snapshot;
        User::factory()->create();
        $this->post(route('reports.export'), $old['filters'] + ['revision' => $old['revision']])->assertStatus(409);
        $current = app(Reports::class)->snapshot($admin, ['kind' => 'collections']);
        $record = (array) DB::table('report_exports')->where('id', $id)->first();
        $record['id'] = (string) Str::uuid();
        $record['state'] = 'generating';
        $record['storage_key'] = $record['checksum'] = $record['row_count'] = $record['byte_count'] = null;
        DB::table('report_exports')->insert($record);
        $this->post(route('reports.export'), $current['filters'] + ['revision' => $current['revision']])->assertStatus(409);
        $this->travelBack();
    }

    public function test_stale_requests_are_audited_and_count_toward_the_five_request_hourly_cap(): void
    {
        $u = $this->admin();
        $old = app(Reports::class)->snapshot($u, ['kind' => 'collections']);
        User::factory()->create();
        for ($n = 0; $n < 5; $n++) {
            try {
                app(Exports::class)->generate($u, $old['filters'], $old['revision']);
                $this->fail('Stale context accepted.');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
        }
        $current = app(Reports::class)->snapshot($u, ['kind' => 'collections']);
        try {
            app(Exports::class)->generate($u, $current['filters'], $current['revision']);
            $this->fail('Sixth request accepted.');
        } catch (HttpException $error) {
            $this->assertSame(429, $error->getStatusCode());
        }
        $this->assertSame(0, DB::table('report_exports')->count());
        $this->assertSame(5, DB::table('report_export_accesses')->where('outcome', 'requested')->count());
        $this->assertSame(5, DB::table('report_export_accesses')->where('outcome', 'failed')->count());
        $this->assertSame(1, DB::table('report_export_accesses')->where('outcome', 'denied')->count());
    }

    public function test_abandoned_generation_lease_cleans_only_its_claimed_temporary_and_publication_paths(): void
    {
        $u = $this->admin();
        $snapshot = app(Reports::class)->snapshot($u, ['kind' => 'collections']);
        $id = (string) Str::uuid();
        DB::table('report_exports')->insert(['id' => $id, 'requester_id' => $u->id, 'kind' => 'collections', 'state' => 'generating', 'filters' => json_encode($snapshot['filters']), 'revision' => $snapshot['revision'], 'authority' => $snapshot['authority'], 'session_version' => $u->session_version, 'generated_at' => $snapshot['generated_at'], 'expires_at' => CarbonImmutable::parse($snapshot['generated_at'])->addDay(), 'storage_key' => $id.'.csv', 'temporary_key' => $id.'.csv.part']);
        $path = app(Exports::class)->path($id.'.csv');
        file_put_contents($path.'.part', 'Private incomplete bytes');
        file_put_contents($path, 'Private crash between publication and COMMIT');
        $this->assertSame(0, app(Exports::class)->cleanup());
        $this->travel(16)->seconds();
        try {
            $this->assertSame(1, app(Exports::class)->cleanup());
            $this->assertSame('failed', DB::table('report_exports')->where('id', $id)->value('state'));
            $this->assertFileDoesNotExist($path);
            $this->assertFileDoesNotExist($path.'.part');
            $this->assertSame(1, DB::table('report_export_accesses')->where('export_id', $id)->where('outcome', 'failed')->count());
            $next = $this->generate($u);
            $this->assertNotSame($id, $next);
        } finally {
            $this->travelBack();
        }
    }

    public function test_caps_timeout_unicode_csv_and_summary_detail_columns_fail_whole_output(): void
    {
        $u = $this->admin();
        $s = app(Reports::class)->snapshot($u, ['kind' => 'collections']);
        foreach (['rows', 'bytes', 'time', 'stops'] as $mode) {
            $copy = $s;
            if ($mode === 'rows') {
                $copy['rows'] = array_fill(0, 10001, ['row_kind' => 'detail']);
            }
            if ($mode === 'bytes') {
                $copy['rows'] = [['row_kind' => 'detail', 'material' => str_repeat('x', 10 * 1024 * 1024)]];
            }
            if ($mode === 'stops') {
                $copy['filters']['kind'] = 'manifest';
                $copy['rows'] = array_fill(0, 51, ['row_kind' => 'current_stop']);
            }
            try {
                app(Csv::class)->encode($copy, microtime(true) - ($mode === 'time' ? 16 : 0));
                $this->fail('Whole-export limit was not enforced.');
            } catch (ExportLimit) {
                $this->addToAssertionCount(1);
            }
        }
        $s['rows'] = [['row_kind' => 'collection', 'public_code' => "Nairobi, \"Kenya\"\nMtaa ".'é', 'estimated_kg' => '1.250']];
        [$bytes,$count] = app(Csv::class)->encode($s, microtime(true));
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $bytes);
        rewind($stream);
        $header = fgetcsv($stream, escape: '');
        $parsed = [];
        while (($row = fgetcsv($stream, escape: '')) !== false) {
            $parsed[] = array_combine($header, $row);
        }fclose($stream);
        $detail = end($parsed);
        $this->assertSame($s['rows'][0]['public_code'], $detail['public_code']);
        $this->assertSame('1.250', $detail['estimated_kg']);
        $this->assertSame(1, $count);
        $this->assertSame($s['revision'], $detail['revision']);
        $this->assertSame('Africa/Nairobi', $detail['timezone']);
        $this->assertStringContainsString("\r\n", $bytes);
    }

    public function test_slow_final_freshness_check_cannot_publish_after_the_generation_deadline(): void
    {
        $actor = $this->admin();
        $snapshot = app(Reports::class)->snapshot($actor, ['kind' => 'collections']);
        $exports = new class extends Exports
        {
            public function current(User $actor, object $row, ?string $sessionFingerprint = null): bool
            {
                $valid = parent::current($actor, $row, $sessionFingerprint);
                usleep(15_100_000);

                return $valid;
            }
        };
        try {
            $exports->generate($actor, $snapshot['filters'], $snapshot['revision']);
            $this->fail('A slow final freshness query published beyond the whole-generation deadline.');
        } catch (HttpException $error) {
            $this->assertSame(422, $error->getStatusCode());
        }
        $row = DB::table('report_exports')->sole();
        $this->assertSame('failed', $row->state);
        $this->assertSame('limit_exceeded', $row->failure_code);
        $this->assertNull($row->storage_key);
        $this->assertNull($row->temporary_key);
        $this->assertFileDoesNotExist($exports->path($row->id.'.csv'));
        $this->assertFileDoesNotExist($exports->path($row->id.'.csv').'.part');
        $this->assertSame(0, DB::table('report_export_accesses')->where('outcome', 'generated')->count());
    }

    public function test_consumer_unscoped_staff_and_tampered_filters_are_denied(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u)->withSession(['auth_session_version' => $u->session_version])->get(route('reports.index', ['kind' => 'batches']))->assertForbidden();
        $this->post(route('reports.export'), ['revision' => str_repeat('a', 64)])->assertForbidden();
        $this->get(route('reports.index', ['owner' => 'someone']))->assertSessionHasErrors('filters');
        $this->get(route('reports.index', ['facility' => 'invalid']))->assertStatus(422);
        foreach ([Role::Processor, Role::Collector] as $role) {
            $u->membership()->update(['role' => $role]);
            $u->refresh();
            $this->get(route($role->value.'.home'))->assertOk()->assertSee('Account access is ready');
            $this->get(route('reports.index'))->assertForbidden();
            $this->get(route('inbox.index'))->assertOk();
        }
    }
}
