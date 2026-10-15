<?php

namespace App\Support\Receipts;

use App\Enums\Role;
use App\Models\User;
use App\Support\Assignments\Eligibility;
use App\Support\Collection\Review;
use App\Support\Routing\Execution;
use Illuminate\Support\Facades\DB;

class Context
{
    public function actor(User $actor): User
    {
        $locked = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
        $locked->setRelation('membership', $locked->membership()->lockForUpdate()->first());
        abort_unless($locked->canSignIn() && in_array($locked->role(), [Role::Collector, Role::Processor], true) && $locked->session_version === $actor->session_version, 403);

        return $locked;
    }

    public function authorized(User $actor, string $id): object
    {
        $r = DB::table('requests')->where('id', $id)->firstOrFail();
        if ($actor->role() === Role::Collector) {
            $org = DB::table('organizations')->where('id', $actor->membership->organization_id)->first();
            abort_unless($org?->review_status === 'approved' && in_array($org->kind, ['collector', 'both'], true), 404);
            abort_unless($r->mode === 'pickup' && DB::table('assignments')->where('request_id', $id)->where('collector_id', $actor->id)->where('membership_id', $actor->membership->id)->where('operator_id', $actor->membership->organization_id)->exists(), 404);
        } else {
            $p = DB::table('places')->where('id', $actor->membership->place_id)->first();
            $o = $p ? DB::table('organizations')->where('id', $p->organization_id)->first() : null;
            abort_unless($r->mode === 'drop_off' && $p && $p->id === $r->point_id && $p->organization_id === $actor->membership->organization_id && $p->active && $p->review_status === 'approved' && $p->kind === 'collection_point' && $o?->review_status === 'approved' && in_array($o->kind, ['processor', 'both'], true), 404);
        }

        return $r;
    }

    public function current(User $actor, string $id): array
    {
        $r = $this->authorized($actor, $id);
        $assignment = $route = null;
        if ($r->mode === 'pickup') {
            $assignment = DB::table('assignments')->where('request_id', $id)->where('collector_id', $actor->id)->where('active', true)->first();
            abort_unless($assignment && $assignment->state === 'accepted' && app(Eligibility::class)->current($assignment), 409, 'This pickup changed or is no longer accepted. Ask the Administrator to review the remainder and obtain a fresh offer, acknowledgment and route.');
            $route = DB::table('route_claims as c')->join('route_approvals as a', 'a.id', '=', 'c.approval_id')->join('routes as ro', function ($j) {
                $j->on('ro.run_id', '=', 'a.run_id')->where('ro.method', 'proposed');
            })->join('route_stops as s', function ($j) {
                $j->on('s.route_id', '=', 'ro.id')->on('s.assignment_id', '=', 'c.record_id');
            })->where('c.kind', 'assignment')->where('c.record_id', $assignment->id)->where('a.state', 'approved')->where('ro.collector_id', $actor->id)->first(['ro.*', 'a.id as approval_id']);
            abort_unless($route && ! DB::table('route_consumptions')->where('route_id', $route->id)->where('assignment_id', $assignment->id)->exists() && ! DB::table('route_finishes')->where('route_id', $route->id)->exists() && app(Execution::class)->current($route->run_id), 409, 'A current approved, unfinished route stop is required. Return to Approved routes; ask the Administrator to resolve changed inputs.');
        } else {
            $review = app(Review::class);
            abort_unless($r->review_state === 'ready' && $review->state($r) === 'ready' && ! $review->assess($r)['blockers'], 409, 'A fresh Ready review is required for the outstanding drop-off items. Ask the Administrator to open Request review.');
        }
        $rows = DB::table('request_items as ri')->join('items as i', 'i.subject_id', '=', 'ri.item_id')->join('subjects as s', 's.id', '=', 'ri.item_id')->join('categories as c', 'c.id', '=', 'i.category_id')->where('ri.request_id', $id)->where('ri.active', true)->orderBy('ri.item_id')->get(['ri.*', 's.public_code', 's.version as subject_version', 'i.model', 'i.subtype', 'i.processing_state', 'c.label as category_label']);
        abort_unless($rows->isNotEmpty() && $rows->every(fn ($row) => $row->processing_state === 'registered' && $row->outcome === 'outstanding' && (int) $row->subject_version === (int) $row->current_subject_version), 409, 'Outstanding items changed. Fresh review is required.');

        return compact('r', 'rows', 'assignment', 'route');
    }
}
