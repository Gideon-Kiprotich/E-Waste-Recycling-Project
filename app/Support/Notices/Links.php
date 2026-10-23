<?php

namespace App\Support\Notices;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class Links
{
    public function for(User $actor, object $notice): string
    {
        $event = DB::table('domain_events')->where('id', $notice->event_id)->first();
        if ($actor->role() === Role::Consumer) {
            $id = DB::table('items')->where('subject_id', $event->subject_id)->where('owner_user_id', $actor->id)->value('subject_id');
            if (! $id && in_array($event->aggregate_kind, ['batch', 'downstream'], true)) {
                $id = DB::table('batch_members as bm')->join('items as i', 'i.subject_id', '=', 'bm.subject_id')->where('bm.batch_id', $event->aggregate_id)->where('i.owner_user_id', $actor->id)->orderBy('i.subject_id')->value('i.subject_id');
            }
            if (! $id && $event->aggregate_kind === 'restriction') {
                $subject = DB::table('restriction_cases')->where('id', $event->aggregate_id)->value('subject_id');
                $id = DB::table('items')->where('subject_id', $subject)->where('owner_user_id', $actor->id)->value('subject_id')
                    ?? DB::table('batch_members as bm')->join('items as i', 'i.subject_id', '=', 'bm.subject_id')->where('bm.batch_id', $subject)->where('i.owner_user_id', $actor->id)->orderBy('i.subject_id')->value('i.subject_id');
            }
            if (! $id && $event->aggregate_kind === 'handover') {
                $id = DB::table('handover_items as hi')->join('items as i', 'i.subject_id', '=', 'hi.subject_id')->where('hi.handover_id', $event->aggregate_id)->where('i.owner_user_id', $actor->id)->orderBy('i.subject_id')->value('i.subject_id');
            }
            if ($event->aggregate_kind === 'assignment') {
                $request = DB::table('assignments as a')->join('requests as r', 'r.id', '=', 'a.request_id')->where('a.id', $event->aggregate_id)->where('r.consumer_id', $actor->id)->value('r.id');
                if ($request) {
                    return route('requests.show', $request);
                }
            }
            if ($event->aggregate_kind === 'route_approval') {
                $request = DB::table('route_approvals as ap')->join('routes as r', 'r.run_id', '=', 'ap.run_id')->join('route_stops as st', 'st.route_id', '=', 'r.id')->join('assignments as a', 'a.id', '=', 'st.assignment_id')->join('requests as req', 'req.id', '=', 'a.request_id')->where('ap.id', $event->aggregate_id)->where('r.method', 'proposed')->where('req.consumer_id', $actor->id)->orderBy('req.id')->value('req.id');
                if ($request) {
                    return route('requests.show', $request);
                }
            }
            if ($id) {
                return route('timeline.show', $id);
            }
            if ($event->aggregate_kind === 'request' && DB::table('requests')->where('id', $event->aggregate_id)->where('consumer_id', $actor->id)->exists()) {
                return route('requests.show', $event->aggregate_id);
            }
        } elseif ($event->aggregate_kind === 'downstream' && $actor->role() === Role::Processor) {
            $shipment = DB::table('downstream_records')->where('event_id', $event->id)->value('shipment_id');
            if ($shipment) {
                return route('downstream.shipment', $shipment);
            }

            return route('downstream.show', $event->aggregate_id);
        } elseif ($event->aggregate_kind === 'downstream_recipient' && $actor->role() === Role::Processor) {
            return route('downstream.shipment', $event->aggregate_id);
        } elseif ($event->aggregate_kind === 'handover') {
            return route('handovers.show', $event->aggregate_id);
        } elseif ($event->aggregate_kind === 'restriction') {
            return route('restrictions.show', $event->aggregate_id);
        } elseif ($event->aggregate_kind === 'assignment' && $actor->role() === Role::Collector) {
            return route('collector.assignments.show', $event->aggregate_id);
        } elseif ($event->aggregate_kind === 'route_approval' && $actor->role() === Role::Collector) {
            $route = DB::table('route_approvals as ap')->join('routes as r', 'r.run_id', '=', 'ap.run_id')->where('ap.id', $event->aggregate_id)->where('r.method', 'proposed')->where('r.collector_id', $actor->id)->value('r.id');
            if ($route) {
                return route('collector.routes.show', $route);
            }
        } elseif ($event->subject_id && $actor->role() === Role::Processor) {
            return route('timeline.show', $event->subject_id);
        }

        return route('dashboard');
    }
}
