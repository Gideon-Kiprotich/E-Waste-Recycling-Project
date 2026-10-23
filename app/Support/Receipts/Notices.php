<?php

namespace App\Support\Receipts;

use App\Support\Notices\Delivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Notices
{
    public function deliver(int $limit = 100): int
    {
        $query = DB::table('notification_outbox as o')->join('receipt_visits as v', 'v.event_id', '=', 'o.event_id')->join('requests as r', 'r.id', '=', 'v.request_id')->join('domain_events as e', 'e.id', '=', 'v.event_id')->where('e.type', 'physical_visit_recorded')->where('e.aggregate_kind', 'request')->whereColumn('o.recipient_id', 'r.consumer_id')->where('o.channel', 'in_app')->where('o.state', 'pending')->orderBy('o.id')->select('o.*', 'v.id as visit_id');

        return app(Delivery::class)->run($query, 'o', function ($row) {
            DB::table('receipt_notifications')->insertOrIgnore(['id' => Str::uuid()->toString(), 'outbox_id' => $row->id, 'visit_id' => $row->visit_id, 'recipient_id' => $row->recipient_id]);
        }, $limit);
    }
}
