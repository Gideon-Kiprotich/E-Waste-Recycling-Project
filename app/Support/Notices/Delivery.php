<?php

namespace App\Support\Notices;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

class Delivery
{
    public function run(Builder $query, string $alias, callable $insert, int $limit = 100, ?callable $authorized = null): int
    {
        $extended = DB::getSchemaBuilder()->hasColumn('notification_outbox', 'delivery_error');
        $delivered = 0;
        for ($i = 0; $i < min(1000, max(1, $limit)); $i++) {
            $claimed = null;
            try {
                $result = DB::transaction(function () use ($query, $alias, $insert, &$claimed, $extended, $authorized) {
                    $q = clone $query;
                    $row = $q->where(fn ($q) => $q->whereNull($alias.'.next_attempt_at')->orWhere($alias.'.next_attempt_at', '<=', now()))->where($alias.'.attempts', '<', 5)->limit(1)->lock('FOR UPDATE OF '.$alias.' SKIP LOCKED')->first();
                    if (! $row) {
                        return null;
                    }
                    $claimed = $row;
                    $recipient = User::with('membership')->find($row->recipient_id);
                    $changed = isset($row->delivery_context) && $row->delivery_context !== null && json_decode($row->delivery_context, true) !== json_decode(DB::selectOne('SELECT restriction_actor_context(?)::text AS context', [$row->recipient_id])->context, true);
                    if ($extended && (! $recipient?->canSignIn() || $changed || ($authorized !== null && ! $authorized($row)))) {
                        DB::table('notification_outbox')->where('id', $row->id)->update(['state' => 'suppressed', 'delivery_error' => 'authority_revoked', 'last_attempt_at' => now()]);

                        return false;
                    }
                    $insert($row);
                    DB::table('notification_outbox')->where('id', $row->id)->update(['state' => 'delivered', 'attempts' => $row->attempts + 1, 'next_attempt_at' => null] + ($extended ? ['delivery_error' => null, 'last_attempt_at' => now()] : []));

                    return true;
                });
                if ($result === null) {
                    break;
                }
                $delivered += (int) $result;
            } catch (Throwable $error) {
                if (! $claimed || ! $extended) {
                    throw $error;
                }
                DB::transaction(function () use ($claimed, $error) {
                    $row = DB::table('notification_outbox')->where('id', $claimed->id)->lockForUpdate()->first();
                    if ($row->state !== 'pending' || $row->attempts !== $claimed->attempts) {
                        return;
                    }
                    if ($error instanceof \PDOException && $error->getCode() === 'P1701') {
                        DB::table('notification_outbox')->where('id', $row->id)->update(['state' => 'suppressed', 'delivery_error' => 'authority_revoked', 'last_attempt_at' => now()]);

                        return;
                    }
                    $attempt = $row->attempts + 1;
                    DB::table('notification_outbox')->where('id', $row->id)->update(['attempts' => $attempt, 'state' => $attempt >= 5 ? 'failed' : 'pending', 'delivery_error' => 'delivery_failed', 'last_attempt_at' => now(), 'next_attempt_at' => $attempt >= 5 ? null : now()->addMinutes([1, 5, 15, 60][$attempt - 1])]);
                });
            }
        }

        return $delivered;
    }
}
