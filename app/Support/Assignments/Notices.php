<?php

namespace App\Support\Assignments;

use App\Support\Notices\Delivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Notices
{
    public function deliver(int $limit = 100): int
    {
        $query = DB::table('notification_outbox as o')->join('domain_events as e', 'e.id', '=', 'o.event_id')->join('assignments as a', 'a.id', '=', 'e.aggregate_id')
            ->where('e.aggregate_kind', 'assignment')->whereIn('e.type', array_map(fn ($s) => 'assignment_'.$s, array_keys(History::LABELS)))->where('o.channel', 'in_app')->where('o.state', 'pending')->orderBy('o.id')->select('o.*', 'a.id as assignment_id');

        return app(Delivery::class)->run($query, 'o', function ($row) {
            DB::table('assignment_notifications')->insertOrIgnore(['id' => Str::uuid()->toString(), 'outbox_id' => $row->id, 'event_id' => $row->event_id, 'recipient_id' => $row->recipient_id, 'assignment_id' => $row->assignment_id]);
        }, $limit);
    }
}
