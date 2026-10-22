<?php

namespace App\Actions;

use App\Enums\Role;
use App\Models\User;
use App\Support\Collection\AdminMutation;
use App\Support\Collection\Review;
use App\Support\Downstream\Context;
use App\Support\Downstream\Input;
use App\Support\Downstream\Violation;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class AcceptDownstreamResponsibility
{
    public function token(User $actor, string $shipment): string
    {
        abort_unless(Context::correctionsAvailable(), 503);
        $this->authorize($actor, $shipment);

        return AdminMutation::hash(['shipment' => $shipment, 'proposal' => (array) DB::table('downstream_recipient_current')->where('shipment_id', $shipment)->firstOrFail(), 'actor' => app(\App\Support\Restrictions\Context::class)->actorContext($actor->id)]);
    }

    private function authorize(User $actor, string $shipment): void
    {
        if ($actor->role() === Role::Administrator) {
            abort_unless(DB::selectOne('SELECT restriction_admin(?) AS ok', [$actor->id])->ok, 404);
        } else {
            app(Context::class)->shipment($actor, $shipment);
        }
    }

    public function record(User $actor, string $shipment, string $kind, array $fields, ?UploadedFile $file = null): string
    {
        abort_unless(in_array($kind, ['accepted', 'cancelled'], true), 404);
        $stored = null;
        try {
            return DB::transaction(function () use ($actor, $shipment, $kind, $fields, $file, &$stored) {
                Review::lock();
                $actor = app(\App\Support\Restrictions\Context::class)->actor($actor);
                $this->authorize($actor, $shipment);
                $fields = Input::validate($fields, 'close', $file);
                $action = 'downstream_recipient_'.$kind.':'.$shipment;
                $hash = AdminMutation::hash(['shipment' => $shipment] + $fields);
                if ($retry = AdminMutation::retry($actor, $action, $fields['idempotency_key'], $hash)) {
                    return $retry;
                }
                $h = DB::table('downstream_shipments')->where('id', $shipment)->firstOrFail();
                DB::table('dismantling_batches')->where('subject_id', $h->batch_id)->lockForUpdate()->firstOrFail();
                DB::table('downstream_shipments')->where('id', $shipment)->lockForUpdate()->firstOrFail();
                $p = DB::table('downstream_recipient_current')->where('shipment_id', $shipment)->firstOrFail();
                abort_unless($p->kind === 'proposed' && (int) $p->version === (int) $fields['expected_version'] && hash_equals($this->token($actor, $shipment), $fields['expected_context']), 409, 'Responsibility or scope changed. Reload this shipment; old authority does not revive.');
                $id = Str::uuid()->toString();
                $command = AdminMutation::command($actor, $action, $fields['idempotency_key'], $hash, $id);
                $event = Str::uuid()->toString();
                $recorded = now();
                $observed = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $fields['observed_at'], 'UTC')->startOfMinute();
                DB::table('domain_events')->insert(['id' => $event, 'subject_id' => null, 'aggregate_kind' => 'downstream_recipient', 'aggregate_id' => $shipment, 'aggregate_version' => $p->version + 1, 'actor_id' => $actor->id, 'command_id' => $command, 'type' => 'downstream_recipient_'.$kind, 'occurred_at' => $observed, 'recorded_at' => $recorded, 'safe_payload' => json_encode(['record_id' => $id, 'milestone' => 'Recipient responsibility '.$kind.'; no physical acknowledgment recorded.'])]);
                $record = array_intersect_key((array) $p, array_flip(['shipment_id', 'review_id', 'receiver_actor_id', 'membership_id', 'scope_version', 'profile_record_id', 'receiver_context']));
                DB::table('downstream_recipient_records')->insert($record + ['id' => $id, 'version' => $p->version + 1, 'predecessor_id' => $p->id, 'kind' => $kind, 'actor_id' => $actor->id, 'reason_ciphertext' => Crypt::encryptString($fields['reason']), 'actor_context' => json_encode(app(\App\Support\Restrictions\Context::class)->actorContext($actor->id)), 'command_id' => $command, 'event_id' => $event, 'observed_at' => $observed, 'recorded_at' => $recorded] + app(DismantleBatches::class)->evidence($actor, $fields, $file, $stored));

                return $id;
            });
        } catch (Throwable $e) {
            app(DismantleBatches::class)->cleanup($stored);
            Violation::raise($e);
        }
    }
}
