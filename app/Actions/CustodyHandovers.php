<?php

namespace App\Actions;

use App\Enums\Role;
use App\Models\User;
use App\Support\Assignments\Input as Reference;
use App\Support\Collection\AdminMutation;
use App\Support\Collection\PrivateEvidence;
use App\Support\Collection\Review;
use App\Support\Handovers\Context;
use App\Support\Handovers\Input;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class CustodyHandovers
{
    public function initiate(User $actor, #[\SensitiveParameter] array $fields, ?UploadedFile $evidence = null): string
    {
        return $this->run($actor, null, 'initiated', $fields, $evidence);
    }

    public function change(User $actor, string $id, string $kind, #[\SensitiveParameter] array $fields, ?UploadedFile $evidence = null): string
    {
        abort_unless(in_array($kind, ['acknowledged', 'withdrawn', 'resolved'], true), 404);

        return $this->run($actor, $id, $kind, $fields, $evidence);
    }

    private function run(User $actor, ?string $id, string $kind, array $fields, ?UploadedFile $evidence): string
    {
        $fields = Input::validate($fields, $evidence, $kind);
        $stored = null;
        try {
            return DB::transaction(function () use ($actor, $id, $kind, $fields, $evidence, &$stored) {
                Review::lock();
                $ctx = app(Context::class);
                $actor = $ctx->actor($actor);
                $action = 'handover_'.$kind.($id ? ':'.$id : '');
                $hash = AdminMutation::hash($fields);
                $h = $id ? DB::table('handovers')->where('id', $id)->lockForUpdate()->firstOrFail() : null;
                if ($h) {
                    $this->authorize($ctx, $actor, $h, $kind);
                } else {
                    abort_unless($ctx->party($actor), 403, 'An active collecting scope and confirmed custody are required.');
                }
                if ($retry = AdminMutation::retry($actor, $action, $fields['idempotency_key'], $hash)) {
                    if (! $h) {
                        $old = DB::table('handovers')->where('id', $retry)->firstOrFail();
                        abort_unless($ctx->access($actor, $old), 404);
                    }

                    return $retry;
                }
                if ($h) {
                    $ctx->lockScopes([$h->sender_actor_id, $h->receiver_actor_id]);
                }
                $observed = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $fields['observed_at'], 'UTC')->startOfMinute();
                if ($observed->isFuture() || ($h && $observed->lt(CarbonImmutable::parse($h->created_at)->startOfMinute()))) {
                    throw ValidationException::withMessages(['observed_at' => 'Use the actual UTC time from initiation through now.']);
                }
                if (! $h) {
                    [$h, $selected] = $this->prepare($actor, $fields, $ctx, $observed);
                } else {
                    abort_unless((int) $h->version === (int) $fields['expected_version'], 409, 'This handover changed. Reload its current outcomes; nothing was recorded.');
                    if ($kind === 'acknowledged') {
                        abort_unless($ctx->live($h, 'sender') && $ctx->live($h, 'receiver'), 409, 'Sender, recipient or destination eligibility changed. Withdraw pending items or ask for discrepancy review; a replacement needs fresh initiation and acknowledgment.');
                    }
                    if ($kind === 'acknowledged' && ! $ctx->compatible($h)) {
                        throw ValidationException::withMessages(['items' => 'Destination capabilities changed. Ask the sender/Administrator to withdraw pending items; a compatible replacement needs fresh initiation and acknowledgment.']);
                    }
                    $selected = $this->select($h, $fields, $kind, $ctx);
                    $h->version++;
                }
                $command = AdminMutation::command($actor, $action, $fields['idempotency_key'], $hash, $h->id);
                $op = $this->operation($actor, $h, $kind, $fields, $evidence, $stored, $command, $observed);
                foreach ($selected as $s) {
                    $outcome = Str::uuid()->toString();
                    DB::table('handover_outcomes')->insert(['id' => $outcome, 'operation_id' => $op, 'handover_item_id' => $s['row']->id, 'outcome' => $s['outcome'], 'attested' => $s['attested'], 'reason_ciphertext' => Crypt::encryptString($s['reason'])]);
                    if ($s['outcome'] === 'accepted') {
                        $this->receive($actor, $h, $s['row'], $outcome, $command, $observed);
                    }
                    $state = match ($s['outcome']) {
                        'pending' => 'pending', 'accepted' => 'accepted', 'withdrawn' => 'withdrawn', 'closed_no_transfer' => 'resolved', default => 'disputed',
                    };
                    if ($state !== $s['row']->state) {
                        DB::table('handover_items')->where('id', $s['row']->id)->update(['state' => $state, 'active' => in_array($state, ['pending', 'disputed'], true)]);
                    }
                }
                $states = DB::table('handover_items')->where('handover_id', $h->id)->pluck('state');
                $state = $states->every(fn ($s) => $s === 'accepted') ? 'accepted' : ($states->contains('disputed') ? 'disputed' : ($states->every(fn ($s) => $s === 'pending') ? 'pending' : ($states->contains('pending') ? 'partial' : 'closed')));
                if ($kind !== 'initiated') {
                    DB::table('handovers')->where('id', $h->id)->update(['state' => $state, 'version' => $h->version]);
                }
                $this->notify($h, $op);

                return $h->id;
            });
        } catch (Throwable $e) {
            if ($stored && ! DB::table('attachments')->where('storage_key', $stored['storage_key'])->exists()) {
                Storage::disk('directory_evidence')->delete($stored['storage_key']);
            }
            if ($e instanceof QueryException && $e->getCode() === '23505' && str_contains($e->errorInfo[2] ?? '', 'handover_subject_active_unique')) {
                abort(409, 'A selected item already has an active transfer. Reload held items.');
            }
            throw $e;
        }
    }

    private function authorize(Context $ctx, User $actor, object $h, string $kind): void
    {
        $admin = $actor->role() === Role::Administrator;
        abort_unless(match ($kind) {
            'resolved' => $admin,
            'withdrawn' => $admin || ($actor->id === $h->sender_actor_id && $ctx->live($h, 'sender')),
            default => $actor->id === $h->receiver_actor_id && $ctx->live($h, 'receiver'),
        }, 404);
    }

    private function prepare(User $actor, array $fields, Context $ctx, CarbonImmutable $observed): array
    {
        [$uid, $version] = Reference::reference($fields['recipient_ref']);
        $ctx->lockScopes([$actor->id, $uid]);
        $recipient = $ctx->recipients($actor)->firstWhere('id', $uid);
        abort_unless($recipient && (int) $recipient->version === $version && (int) $recipient->destination_version === (int) $fields['destination_version'] && (int) $recipient->operator_version === (int) $fields['operator_version'], 409, 'Receiving scope or destination changed. Reload eligible recipients.');
        $m = DB::table('memberships')->where('user_id', $uid)->lockForUpdate()->firstOrFail();
        $party = DB::table('parties')->where('place_id', $m->place_id)->value('id');
        if (! $party) {
            $party = Str::uuid()->toString();
            DB::table('parties')->insert(['id' => $party, 'kind' => 'place', 'place_id' => $m->place_id]);
        }
        $h = (object) ['id' => Str::uuid()->toString(), 'sender_party_id' => $ctx->party($actor), 'receiver_party_id' => $party, 'sender_actor_id' => $actor->id, 'receiver_actor_id' => $uid, 'sender_membership_id' => $actor->membership->id, 'receiver_membership_id' => $m->id, 'sender_scope_version' => $actor->membership->version, 'receiver_scope_version' => $m->version, 'destination_version' => (int) $fields['destination_version'], 'operator_version' => (int) $fields['operator_version'], 'state' => 'pending', 'version' => 1, 'created_at' => now()->toDateTimeString()];
        $selected = [];
        $ids = array_map(fn ($ref) => Reference::reference($ref)[0], $fields['subjects']);
        DB::table('subjects')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        DB::table('items')->whereIn('subject_id', $ids)->orderBy('subject_id')->lockForUpdate()->get();
        $custody = DB::table('custody_current')->whereIn('subject_id', $ids)->orderBy('subject_id')->lockForUpdate()->get();
        DB::table('handovers')->insert((array) $h);
        foreach ($fields['subjects'] as $ref) {
            [$id, $v] = Reference::reference($ref);
            $held = $ctx->held($actor)->firstWhere('subject_id', $id);
            $c = $custody->firstWhere('subject_id', $id);
            abort_unless($held && $c && (int) $held->version === $v, 409, 'An item is no longer available in your confirmed custody. Reload held items.');
            $first = DB::table('first_receipts as f')->join('receipt_outcomes as o', 'o.id', '=', 'f.outcome_id')->join('receipt_visits as v', 'v.id', '=', 'o.visit_id')->where('f.subject_id', $id)->value('v.observed_at');
            abort_unless($first && $observed->gte(CarbonImmutable::parse($first)->startOfMinute()), 422, 'Handover time cannot precede first receipt.');
            $i = DB::table('items')->where('subject_id', $id)->firstOrFail();
            if (! DB::table('place_categories')->where('place_id', $m->place_id)->where('category_id', $i->category_id)->where('subtype', $i->subtype ?? '')->exists()) {
                throw ValidationException::withMessages(['subjects' => 'The receiving place does not explicitly accept every selected category/subtype. Choose a compatible destination or a smaller manifest.']);
            }
            $row = (object) ['id' => Str::uuid()->toString(), 'handover_id' => $h->id, 'subject_id' => $id, 'source_event_id' => $c->last_event_id, 'source_custody_version' => $c->version, 'source_subject_version' => $v, 'state' => 'pending', 'active' => true];
            DB::table('handover_items')->insert((array) $row);
            $selected[] = ['row' => $row, 'outcome' => 'pending', 'attested' => false, 'reason' => $fields['reason']];
        }

        return [$h, $selected];
    }

    private function select(object $h, array $fields, string $kind, Context $ctx): array
    {
        $rows = $ctx->rows($h->id);
        $ids = $rows->pluck('subject_id');
        DB::table('subjects')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        DB::table('custody_current')->whereIn('subject_id', $ids)->orderBy('subject_id')->lockForUpdate()->get();
        DB::table('handover_items')->where('handover_id', $h->id)->orderBy('subject_id')->lockForUpdate()->get();
        $selected = [];
        foreach ($fields['items'] as $f) {
            $row = $rows->firstWhere('id', $f['id']);
            abort_unless($row && hash_equals($row->public_code, $f['code']) && $row->state === ($kind === 'resolved' ? 'disputed' : 'pending'), 409, 'An item outcome changed or does not match this manifest. Reload; other records are not disclosed.');
            $selected[] = ['row' => $row] + $f;
        }

        return $selected;
    }

    private function operation(User $actor, object $h, string $kind, array $fields, ?UploadedFile $file, ?array &$stored, string $command, CarbonImmutable $observed): string
    {
        $id = Str::uuid()->toString();
        $event = AdminMutation::event($actor, 'handover', $h->id, $h->version, 'handover_'.$kind, $command, ['operation_id' => $id]);
        $attachment = null;
        if ($file) {
            $stored = app(PrivateEvidence::class)->store($file);
            $attachment = Str::uuid()->toString();
            DB::table('attachments')->insert($stored + ['id' => $attachment, 'uploaded_by' => $actor->id, 'privacy_class' => 'private']);
        }
        DB::table('handover_operations')->insert(['id' => $id, 'handover_id' => $h->id, 'version' => $h->version, 'kind' => $kind, 'actor_id' => $actor->id, 'command_id' => $command, 'event_id' => $event, 'observed_at' => $observed, 'recorded_at' => now(), 'evidence_kind' => $fields['evidence_kind'], 'attachment_id' => $attachment, 'reference_ciphertext' => $fields['evidence_kind'] === 'operator_reference' ? Crypt::encryptString(json_encode(['issuer' => $fields['reference_issuer'], 'code' => $fields['reference_code'], 'asserted_by' => $actor->id, 'provenance' => 'Staff assertion; not independently verified'], JSON_THROW_ON_ERROR)) : null]);

        return $id;
    }

    private function receive(User $actor, object $h, object $row, string $outcome, string $command, CarbonImmutable $observed): void
    {
        $event = Str::uuid()->toString();
        $destinationKind = DB::table('parties as p')->join('places as l', 'l.id', '=', 'p.place_id')->where('p.id', $h->receiver_party_id)->value('l.kind');
        DB::table('domain_events')->insert(['id' => $event, 'subject_id' => $row->subject_id, 'aggregate_kind' => 'item', 'aggregate_id' => $row->subject_id, 'aggregate_version' => $row->source_subject_version + 1, 'actor_id' => $actor->id, 'type' => 'handover_accepted', 'occurred_at' => $observed, 'recorded_at' => now(), 'command_id' => $command, 'safe_payload' => json_encode(['handover_id' => $h->id, 'outcome_id' => $outcome, 'destination_kind' => $destinationKind, 'from_party_id' => $h->sender_party_id, 'recipient_party_id' => $h->receiver_party_id, 'acknowledged' => true])]);
        DB::table('handover_receipts')->insert(['outcome_id' => $outcome, 'handover_item_id' => $row->id, 'event_id' => $event, 'subject_id' => $row->subject_id, 'custody_version' => $row->source_custody_version + 1, 'subject_version' => $row->source_subject_version + 1]);
        DB::table('subjects')->where('id', $row->subject_id)->update(['version' => $row->source_subject_version + 1]);
        DB::table('custody_current')->where('subject_id', $row->subject_id)->update(['party_id' => $h->receiver_party_id, 'last_event_id' => $event, 'version' => $row->source_custody_version + 1]);
    }

    private function notify(object $h, string $op): void
    {
        $owners = DB::table('handover_outcomes as oc')->join('handover_items as hi', 'hi.id', '=', 'oc.handover_item_id')->join('items as i', 'i.subject_id', '=', 'hi.subject_id')->where('oc.operation_id', $op)->pluck('i.owner_user_id')->all();
        $event = DB::table('handover_operations')->where('id', $op)->value('event_id');
        foreach (array_unique([$h->sender_actor_id, $h->receiver_actor_id, ...$owners]) as $uid) {
            DB::table('notification_outbox')->insert(['id' => Str::uuid()->toString(), 'event_id' => $event, 'recipient_id' => $uid, 'channel' => 'in_app', 'state' => 'pending']);
        }
    }
}
