<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Support\Assessments\Context;
use App\Support\Reporting\Manifest;
use App\Support\Reporting\Reports;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class RoleHomeController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        return redirect()->route($request->user()->role()->value.'.home');
    }

    public function show(Request $request): Response
    {
        $role = $request->user()->role();
        $consumerAssignments = collect();
        if ($role === Role::Consumer && DB::getSchemaBuilder()->hasTable('assignments')) {
            $consumerAssignments = DB::table('assignments as a')->join('requests as r', 'r.id', '=', 'a.request_id')->where('r.consumer_id', $request->user()->id)->where('a.active', true)->orderByDesc('a.created_at')->limit(5)->get(['a.*', 'r.public_code']);
        }

        $dashboard = null;
        if (DB::getSchemaBuilder()->hasTable('report_exports')) {
            $canReport = match ($role) {
                Role::Processor => app(Context::class)->scope($request->user()) !== null,
                Role::Collector => app(Manifest::class)->eligibleScope($request->user()),
                default => true,
            };
            $dashboard = $canReport ? app(Reports::class)->snapshot($request->user(), []) : null;
        }

        return response()->view('role-home', compact('role', 'consumerAssignments', 'dashboard'))->header('Cache-Control', 'private, no-store');
    }
}
