<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Support\AuthenticatedSession;
use App\Support\Reporting\Exports;
use App\Support\Reporting\Reports;
use App\Support\Reporting\ReportSession;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Tests\Support\ReportingSchema;
use Tests\TestCase;

class ReportingSessionTest extends TestCase
{
    use ReportingSchema { tearDown as schemaTearDown; }

    protected function tearDown(): void
    {
        foreach (DB::table('report_exports')->pluck('id') as $id) {
            @unlink(app(Exports::class)->path($id.'.csv'));
        }
        $this->schemaTearDown();
    }

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->membership()->update(['role' => Role::Administrator]);

        return $u->refresh();
    }

    public function test_session_fingerprints_bind_retrieval_and_signout_invalidates_without_account_version_changes(): void
    {
        $u = $this->admin();
        $store = new Store('reporting', new ArraySessionHandler(120));
        $store->start();
        $request = Request::create('/reports');
        $request->setLaravelSession($store);
        $request->setUserResolver(fn () => $u);
        $fingerprint = ReportSession::fingerprint($request);
        $snapshot = app(Reports::class)->snapshot($u, [], $fingerprint);
        $id = app(Exports::class)->generate($u, $snapshot['filters'], $snapshot['revision'], $fingerprint);
        $this->assertNotNull(app(Exports::class)->retrieve($u, $id, $fingerprint));
        $version = $u->session_version;
        app(AuthenticatedSession::class)->end($request);
        $this->assertSame('invalidated', DB::table('report_exports')->where('id', $id)->value('state'));
        $this->assertSame($version, $u->fresh()->session_version);
        app(AuthenticatedSession::class)->start($request, $u);
        $new = ReportSession::fingerprint($request);
        $this->assertNotSame($fingerprint, $new);
        $this->assertNull(app(Exports::class)->retrieve($u, $id, $new));
        $this->assertNotSame($snapshot['revision'], app(Reports::class)->snapshot($u, [], $new)['revision']);
    }

    public function test_http_snapshot_generation_download_and_session_rotation_require_the_same_authenticated_session(): void
    {
        $u = $this->admin();
        $this->actingAs($u)->withSession(['auth_session_version' => $u->session_version]);
        $name = config('session.cookie');
        $this->withCookie($name, app('session')->getId());
        $snapshot = $this->get(route('reports.index'))->assertOk()->viewData('snapshot');
        $this->post(route('reports.export'), $snapshot['filters'] + ['revision' => $snapshot['revision']])->assertRedirect();
        $row = DB::table('report_exports')->firstOrFail();
        $this->assertNotNull($row->session_fingerprint);
        $response = $this->get(route('reports.download', $row->id))->assertOk();
        ob_start();
        $response->baseResponse->sendContent();
        $bytes = ob_get_clean();
        $this->assertStringContainsString($snapshot['revision'], $bytes);
        app('session')->regenerate();
        $this->withCookie($name, app('session')->getId())->withSession(['auth_session_version' => $u->session_version]);
        $this->post(route('reports.export'), $snapshot['filters'] + ['revision' => $snapshot['revision']])->assertStatus(409);
        $this->get(route('reports.download', $row->id))->assertNotFound();
        $this->assertSame('invalidated', DB::table('report_exports')->where('id', $row->id)->value('state'));
    }
}
