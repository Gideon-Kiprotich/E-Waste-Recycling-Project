<?php

namespace App\Support\Collection;

use App\Enums\Role;
use App\Models\User;
use App\Support\Devices\Identifiers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminMutation
{
    public static function actor(User $actor): User
    {
        Review::lock();
        $locked = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
        $locked->setRelation('membership', $locked->membership()->lockForUpdate()->first());
        abort_unless($locked->canSignIn() && $locked->role() === Role::Administrator && $locked->session_version === $actor->session_version, 403);

        return $locked;
    }

    public static function hash(#[\SensitiveParameter] array $fields): string
    {
        unset($fields['idempotency_key']);
        ksort($fields);

        return Identifiers::digest(json_encode($fields, JSON_THROW_ON_ERROR), 'command');
    }

    public static function retry(User $actor, string $action, string $key, string $hash): ?string
    {
        $command = DB::table('commands')->where('actor_id', $actor->id)->where('action', $action)->where('idempotency_key', $key)->first();
        if (! $command) {
            return null;
        }
        abort_unless(hash_equals($hash, $command->payload_hash), 409, 'This submission reference was used for different information. Open a fresh form.');

        return json_decode($command->safe_response, true, flags: JSON_THROW_ON_ERROR)['record_id'];
    }

    public static function command(User $actor, string $action, string $key, string $hash, string $id): string
    {
        $command = Str::uuid()->toString();
        DB::table('commands')->insert(['id' => $command, 'actor_id' => $actor->id, 'action' => $action, 'idempotency_key' => $key, 'payload_hash' => $hash, 'status' => 'completed', 'safe_response' => json_encode(['record_id' => $id])]);

        return $command;
    }

    public static function event(User $actor, string $kind, string $id, int $version, string $type, string $command, array $payload): string
    {
        $event = Str::uuid()->toString();
        DB::table('domain_events')->insert(['id' => $event, 'subject_id' => null, 'aggregate_kind' => $kind, 'aggregate_id' => $id, 'aggregate_version' => $version, 'actor_id' => $actor->id, 'type' => $type, 'occurred_at' => now(), 'recorded_at' => now(), 'command_id' => $command, 'safe_payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);

        return $event;
    }
}
