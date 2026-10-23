<?php

namespace App\Support\Restrictions;

use App\Support\Notices\Delivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Notices
{
    public function deliver(int $limit = 100): int
    {
        $query = DB::table('notification_outbox as n')->join('restriction_records as r', 'r.event_id', '=', 'n.event_id')->join('restriction_cases as c', 'c.id', '=', 'r.case_id')->join('domain_events as e', 'e.id', '=', 'r.event_id')->where('e.aggregate_kind', 'restriction')->whereRaw("e.type='restriction_'||r.kind")->where('n.channel', 'in_app')->where('n.state', 'pending')->where(function ($q) {
            $q->whereColumn('n.recipient_id', 'r.actor_id')->orWhereColumn('n.recipient_id', 'c.created_by')
                ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('restriction_records as a')->whereColumn('a.case_id', 'c.id')->where('a.kind', 'assign')->whereColumn('a.assigned_to', 'n.recipient_id'))
                ->orWhereRaw("c.contract='P16' AND EXISTS(SELECT 1 FROM restriction_records invitation WHERE invitation.case_id=c.id AND invitation.kind='assign' AND invitation.details->>'party_actor_id'=n.recipient_id::text)")
                ->orWhereRaw("c.contract='P16' AND c.fact_key='downstream_recipient' AND EXISTS(SELECT 1 FROM restriction_records proposal WHERE proposal.case_id=c.id AND proposal.kind='proposal' AND proposal.details->>'receiver_actor_id'=n.recipient_id::text)")
                ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('items as i')->whereColumn('i.subject_id', 'c.subject_id')->whereColumn('i.owner_user_id', 'n.recipient_id'))
                ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('batch_members as bm')->join('items as owner_item', 'owner_item.subject_id', '=', 'bm.subject_id')->whereColumn('bm.batch_id', 'c.subject_id')->whereColumn('owner_item.owner_user_id', 'n.recipient_id'));
        })->orderBy('n.id')->select('n.*', 'c.id as case_id');

        return app(Delivery::class)->run($query, 'n', function ($row) {
            DB::table('restriction_notifications')->insertOrIgnore(['id' => Str::uuid()->toString(), 'outbox_id' => $row->id, 'event_id' => $row->event_id, 'case_id' => $row->case_id, 'recipient_id' => $row->recipient_id]);
        }, $limit);
    }
}
