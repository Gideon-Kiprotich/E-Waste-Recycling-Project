<?php

namespace App\Support\Reporting;

use App\Enums\Role;
use App\Models\User;
use App\Support\Assignments\Eligibility;
use App\Support\Routing\Execution;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class Manifest
{
    public function eligibleScope(User $actor): bool
    {
        $actor->load('membership');

        return $actor->canSignIn() && $actor->role() === Role::Collector && DB::table('organizations')->where('id', $actor->membership->organization_id)->where('review_status', 'approved')->whereIn('kind', ['collector', 'both'])->exists();
    }

    public function work(User $actor): array
    {
        abort_unless($this->eligibleScope($actor), 403, 'Current reviewed Collector scope is required.');
        $counts = ['Offered assignments (not scheduled)' => 0, 'Accepted assignments (not a booking)' => 0];
        foreach (DB::table('assignments')->where('collector_id', $actor->id)->where('active', true)->whereIn('state', ['offered', 'accepted'])->get() as $a) {
            if (app(Eligibility::class)->current($a)) {
                $counts[$a->state === 'offered' ? 'Offered assignments (not scheduled)' : 'Accepted assignments (not a booking)']++;
            }
        }

        return $counts;
    }

    public function rows(User $actor): array
    {
        abort_unless($this->eligibleScope($actor), 403, 'Current reviewed Collector scope is required.');
        $rows = [];
        $routes = DB::table('routes as r')->join('route_approvals as a', 'a.run_id', '=', 'r.run_id')->where('r.collector_id', $actor->id)->where('r.method', 'proposed')->where('a.state', 'approved')->whereNotExists(fn ($q) => $q->from('route_finishes')->whereColumn('route_id', 'r.id'))->orderBy('r.id')->get(['r.*']);
        foreach ($routes as $r) {
            if (! app(Execution::class)->current($r->run_id)) {
                continue;
            }
            foreach (DB::table('route_stops as s')->join('assignments as a', 'a.id', '=', 's.assignment_id')->join('requests as req', 'req.id', '=', 'a.request_id')->where('s.route_id', $r->id)->where('a.collector_id', $actor->id)->where('a.active', true)->where('a.state', 'accepted')->whereNotExists(fn ($q) => $q->from('route_consumptions as co')->whereColumn('co.route_id', 's.route_id')->whereColumn('co.assignment_id', 's.assignment_id'))->orderBy('s.position')->get(['s.*', 'a.request_id', 'a.facility_id', 'req.private_details_id', 'req.public_code']) as $stop) {
                $items = DB::table('request_items as ri')->join('subjects as s', 's.id', '=', 'ri.item_id')->where('ri.request_id', $stop->request_id)->where('ri.active', true)->where('ri.outcome', 'outstanding')->orderBy('s.public_code')->get(['s.public_code', 'ri.preferred_date']);
                $private = DB::table('request_private_details')->where('id', $stop->private_details_id)->first();
                $rows[] = ['row_kind' => 'current_stop', 'id' => $r->id.':'.$stop->position, 'route_id' => $r->id, 'position' => $stop->position, 'public_code' => $stop->public_code, 'item_codes' => $items->pluck('public_code')->implode('|'), 'item_count' => $items->count(), 'estimated_kg' => $stop->estimated_kg, 'preferred_dates_not_booked' => $items->pluck('preferred_date')->unique()->implode('|'),
                    'contact' => Crypt::decryptString($private->encrypted_contact), 'address' => Crypt::decryptString($private->encrypted_address), 'latitude' => $private->latitude, 'longitude' => $private->longitude, 'destination' => DB::table('places')->where('id', $stop->facility_id)->value('name')];
            }
        }

        return $rows;
    }
}
