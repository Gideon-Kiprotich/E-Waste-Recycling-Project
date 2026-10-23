<?php

namespace App\Support\Notices;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Milestones
{
    public function deliver(int $limit = 100): int
    {
        $query = DB::table('notification_outbox as n')->join('domain_events as e', 'e.id', '=', 'n.event_id')->where('n.channel', 'in_app')->where('n.state', 'pending')->where('n.delivery_version', 'C17-1')->whereRaw('milestone_type(e.type)')->orderBy('n.id')->select('n.*');

        return app(Delivery::class)->run($query, 'n', function ($r) {
            DB::table('milestone_notifications')->insertOrIgnore(['id' => Str::uuid()->toString(), 'outbox_id' => $r->id, 'event_id' => $r->event_id, 'recipient_id' => $r->recipient_id]);
        }, $limit, fn ($row) => (bool) DB::selectOne('SELECT milestone_delivery_allowed(e,?) AS allowed FROM domain_events e WHERE id=?', [$row->recipient_id, $row->event_id])->allowed);
    }
}
