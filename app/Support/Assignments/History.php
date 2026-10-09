<?php

namespace App\Support\Assignments;

use App\Models\User;
use App\Support\Collection\AdminMutation;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class History
{
    public const LABELS = ['offered' => 'Offer awaiting acknowledgment', 'accepted' => 'Accepted — not scheduled', 'declined' => 'Offer declined', 'withdrawn' => 'Assignment withdrawn', 'replaced' => 'Assignment replaced', 'invalidated' => 'Assignment changed — Administrator resolution required'];

    public static function record(User $actor, object $assignment, string $command, #[\SensitiveParameter] ?string $reason = null, ?string $code = null): void
    {
        $event = AdminMutation::event($actor, 'assignment', $assignment->id, $assignment->version, 'assignment_'.$assignment->state, $command, ['request_id' => $assignment->request_id, 'state' => $assignment->state, 'reason_code' => $code]);
        DB::table('assignment_revisions')->insert(['id' => Str::uuid()->toString(), 'assignment_id' => $assignment->id, 'version' => $assignment->version, 'event_id' => $event, 'reason_ciphertext' => $reason ? Crypt::encryptString($reason) : null]);
        $owner = DB::table('requests')->where('id', $assignment->request_id)->value('consumer_id');
        foreach (array_unique([$owner, $assignment->collector_id, $assignment->created_by]) as $recipient) {
            DB::table('notification_outbox')->insert(['id' => Str::uuid()->toString(), 'event_id' => $event, 'recipient_id' => $recipient, 'channel' => 'in_app', 'state' => 'pending']);
        }
    }

    public static function close(User $actor, object $assignment, string $state, string $command, #[\SensitiveParameter] ?string $reason = null, ?string $code = null): void
    {
        DB::table('assignments')->where('id', $assignment->id)->update(['state' => $state, 'active' => false, 'version' => (int) $assignment->version + 1]);
        self::record($actor, DB::table('assignments')->where('id', $assignment->id)->first(), $command, $reason, $code);
    }

    public static function invalidate(User $actor, string $requestId, string $command, string $code): void
    {
        // Upgrade fixtures and authorized historical retries still support pre-assignment schemas.
        if (! DB::getSchemaBuilder()->hasTable('assignments')) {
            return;
        }
        foreach (DB::table('assignments')->where('request_id', $requestId)->where('active', true)->orderBy('id')->lockForUpdate()->get() as $assignment) {
            self::close($actor, $assignment, 'invalidated', $command, null, $code);
        }
    }
}
