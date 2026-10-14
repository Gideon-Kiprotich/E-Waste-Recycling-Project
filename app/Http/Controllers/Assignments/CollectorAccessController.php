<?php

namespace App\Http\Controllers\Assignments;

use App\Actions\ManageCollector;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CollectorAccessController extends Controller
{
    public function index()
    {
        $collectors = DB::table('memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')->leftJoin('organizations as o', 'o.id', '=', 'm.organization_id')->where('m.role', 'collector')->orderBy('u.display_name')->paginate(12, ['u.id', 'u.display_name', 'u.status', 'm.active', 'm.version', 'o.name as operator_name']);

        return response()->view('assignments.collectors', compact('collectors'))->header('Cache-Control', 'private, no-store');
    }

    public function form(?string $collector = null)
    {
        $entity = $collector ? DB::table('users as u')->join('memberships as m', 'm.user_id', '=', 'u.id')->where('u.id', $collector)->where('m.role', 'collector')->firstOrFail(['u.id', 'u.display_name', 'u.status', 'm.version', 'm.organization_id', 'm.active']) : null;
        $organizations = DB::table('organizations')->where('review_status', 'approved')->whereIn('kind', ['collector', 'both'])->orderBy('name')->get(['id', 'name', 'version', 'synthetic']);
        $options = $organizations->mapWithKeys(fn ($o) => [$o->id.':'.$o->version => $o->name.($o->synthetic ? ' · Synthetic study record' : '')])->all();

        $currentOperator = $entity?->organization_id ? DB::table('organizations')->where('id', $entity->organization_id)->first(['id', 'name', 'kind', 'review_status', 'version']) : null;
        $organizationValue = $currentOperator && isset($options[$currentOperator->id.':'.$currentOperator->version]) ? $currentOperator->id.':'.$currentOperator->version : '';

        return response()->view('assignments.collector-form', compact('entity', 'options', 'currentOperator', 'organizationValue'))->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $request, ManageCollector $action, ?string $collector = null)
    {
        $id = $action->save($request->user(), $collector, $request->except('_token'));

        return redirect()->route('admin.collectors.edit', $id)->with('status', 'Collector access saved. Unchanged active links preserve sessions and assignments; actual access changes revoke sessions and invalidate active assignments. No email was sent.');
    }
}
