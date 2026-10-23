<?php

namespace App\Http\Controllers;

use App\Support\Reporting\Exports;
use App\Support\Reporting\PrivateCsvResponse;
use App\Support\Reporting\Reports;
use App\Support\Reporting\ReportSession;
use App\Support\Reporting\Timeline;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ReportingController extends Controller
{
    public function index(Request $request, Reports $reports)
    {
        $snapshot = $reports->snapshot($request->user(), $request->query(), ReportSession::fingerprint($request));
        $page = max(1, (int) $request->query('page', 1));
        $rows = new LengthAwarePaginator(array_slice($snapshot['rows'], ($page - 1) * 25, 25), count($snapshot['rows']), 25, $page, ['path' => $request->url(), 'query' => $request->query()]);
        $exports = DB::table('report_exports')->where('requester_id', $request->user()->id)->orderByDesc('generated_at')->limit(10)->get(['id', 'kind', 'state', 'generated_at', 'expires_at']);

        return response()->view('reporting.index', compact('snapshot', 'rows', 'exports'))->header('Cache-Control', 'private, no-store');
    }

    public function timeline(Request $request, string $subject, Timeline $timeline)
    {
        $item = $timeline->subject($request->user(), $subject);
        $cursor = $request->query('cursor');
        abort_unless($cursor === null || is_string($cursor), 422, 'Invalid timeline cursor.');
        $events = $timeline->events($request->user(), $subject, $cursor);
        $status = $timeline->status($request->user(), $subject);

        return response()->view('reporting.timeline', compact('item', 'events', 'status'))->header('Cache-Control', 'private, no-store');
    }

    public function export(Request $request, Exports $exports)
    {
        $request->validate(['revision' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/']]);
        $id = $exports->generate($request->user(), $request->except('_token', 'revision'), $request->string('revision')->toString(), ReportSession::fingerprint($request));

        return redirect()->route('reports.index', $request->only('kind', 'from', 'to', 'category', 'subtype', 'facility', 'output_type', 'material'))->with('status', 'Private export generated. Download it from Recent exports before expiry.');
    }

    public function download(Request $request, string $export, Exports $exports)
    {
        $connection = DB::connection();
        $connection->select('SELECT pg_advisory_lock(481204)');
        $release = fn () => $connection->select('SELECT pg_advisory_unlock(481204)');
        try {
            $bytes = $exports->retrieve($request->user(), $export, ReportSession::fingerprint($request));
            abort_unless($bytes !== null, 404, 'Export unavailable. Refresh and generate a new export.');

            return (new PrivateCsvResponse($bytes, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="ewaste-report-'.$export.'.csv"',
                'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            ]))->hold($release);
        } catch (\Throwable $error) {
            $release();
            throw $error;
        }
    }
}
