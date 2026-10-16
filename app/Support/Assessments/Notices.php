<?php

namespace App\Support\Assessments;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Notices
{
    public function deliver(int $limit = 100): int
    {
        return DB::transaction(function () use ($limit) {
            $rows = DB::table('notification_outbox as n')->join('inspection_steps as s', 's.event_id', '=', 'n.event_id')->join('domain_events as e', 'e.id', '=', 's.event_id')->join('items as i', 'i.subject_id', '=', 's.subject_id')->whereColumn('e.type', 's.event_type')->where('e.aggregate_kind', 'item')->where('n.channel', 'in_app')->where('n.state', 'pending')->where(fn ($q) => $q->whereColumn('n.recipient_id', 's.actor_id')->orWhereColumn('n.recipient_id', 'i.owner_user_id'))->orderBy('n.id')->limit($limit)->select('n.*')->lock('FOR UPDATE OF n SKIP LOCKED')->get();
            foreach ($rows as $r) {
                DB::table('assessment_notifications')->insertOrIgnore(['id' => Str::uuid()->toString(), 'outbox_id' => $r->id, 'event_id' => $r->event_id, 'recipient_id' => $r->recipient_id]);
                DB::table('notification_outbox')->where('id', $r->id)->update(['state' => 'delivered', 'attempts' => $r->attempts + 1]);
            }

            return $rows->count();
        });
    }
}
