<?php

namespace App\Support\Receipts;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Notices
{
    public function deliver(int $limit = 100): int
    {
        return DB::transaction(function () use ($limit) {
            $rows = DB::table('notification_outbox as o')->join('receipt_visits as v', 'v.event_id', '=', 'o.event_id')->join('requests as r', 'r.id', '=', 'v.request_id')->join('domain_events as e', 'e.id', '=', 'v.event_id')->where('e.type', 'physical_visit_recorded')->where('e.aggregate_kind', 'request')->whereColumn('o.recipient_id', 'r.consumer_id')->where('o.channel', 'in_app')->where('o.state', 'pending')->orderBy('o.id')->limit($limit)->select('o.*', 'v.id as visit_id')->lock('FOR UPDATE OF o SKIP LOCKED')->get();
            foreach ($rows as $row) {
                DB::table('receipt_notifications')->insertOrIgnore(['id' => Str::uuid()->toString(), 'outbox_id' => $row->id, 'visit_id' => $row->visit_id, 'recipient_id' => $row->recipient_id]);
                DB::table('notification_outbox')->where('id', $row->id)->update(['state' => 'delivered', 'attempts' => $row->attempts + 1]);
            }

            return $rows->count();
        });
    }
}
