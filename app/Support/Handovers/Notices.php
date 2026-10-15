<?php

namespace App\Support\Handovers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Notices
{
    public function deliver(int $limit = 100): int
    {
        return DB::transaction(function () use ($limit) {
            $rows = DB::table('notification_outbox as n')->join('handover_operations as op', 'op.event_id', '=', 'n.event_id')->join('handovers as h', 'h.id', '=', 'op.handover_id')->join('domain_events as e', 'e.id', '=', 'op.event_id')->where('e.aggregate_kind', 'handover')->whereRaw("e.type='handover_'||op.kind")->whereIn('op.kind', ['initiated', 'acknowledged', 'withdrawn', 'resolved'])->where('n.channel', 'in_app')->where('n.state', 'pending')->where(fn ($q) => $q->whereColumn('n.recipient_id', 'h.sender_actor_id')->orWhereColumn('n.recipient_id', 'h.receiver_actor_id')->orWhereExists(fn ($s) => $s->selectRaw('1')->from('handover_outcomes as oc')->join('handover_items as hi', 'hi.id', '=', 'oc.handover_item_id')->join('items as i', 'i.subject_id', '=', 'hi.subject_id')->whereColumn('oc.operation_id', 'op.id')->whereColumn('i.owner_user_id', 'n.recipient_id')))->orderBy('n.id')->limit($limit)->select('n.*', 'op.id as operation_id')->lock('FOR UPDATE OF n SKIP LOCKED')->get();
            foreach ($rows as $row) {
                DB::table('handover_notifications')->insertOrIgnore(['id' => Str::uuid()->toString(), 'outbox_id' => $row->id, 'operation_id' => $row->operation_id, 'recipient_id' => $row->recipient_id]);
                DB::table('notification_outbox')->where('id', $row->id)->update(['state' => 'delivered', 'attempts' => $row->attempts + 1]);
            }

            return $rows->count();
        });
    }
}
