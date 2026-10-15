<?php

namespace App\Actions;

use App\Models\User;
use App\Support\Assignments\History;
use App\Support\Collection\AdminMutation;
use App\Support\Collection\PrivateEvidence;
use App\Support\Collection\Review;
use App\Support\Receipts\Context;
use App\Support\Receipts\Input;
use App\Support\Routing\Execution;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class RecordVisit
{
    public function record(User $actor, string $id, #[\SensitiveParameter] array $fields, ?UploadedFile $evidence = null): string
    {
        $fields = Input::validate($fields, $evidence);
        $stored = null;
        try {
            return DB::transaction(function () use ($actor, $id, $fields, $evidence, &$stored) {
                Review::lock();
                $context = app(Context::class);
                $actor = $context->actor($actor);
                $context->authorized($actor, $id);
                $action = 'record_visit:'.$id;
                $hash = AdminMutation::hash($fields);
                if ($retry = AdminMutation::retry($actor, $action, $fields['idempotency_key'], $hash)) {
                    return $retry;
                }
                $work = $this->lockWork($context, $actor, $id);
                ['r' => $r,'rows' => $rows,'assignment' => $assignment,'route' => $route] = $work;
                abort_unless((int) $r->version === (int) $fields['expected_version'] && ($assignment?->id === ($fields['assignment_id'] ?? null)) && ($route?->id === ($fields['route_id'] ?? null)) && (int) ($assignment?->version ?? 0) === (int) ($fields['expected_assignment_version'] ?? 0), 409, 'This request, assignment or route changed. Reload current work; no receipt was recorded.');
                $observed = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $fields['observed_at'], 'UTC')->startOfMinute();
                if ($observed->isFuture() || $observed->lt(CarbonImmutable::parse($r->created_at)->startOfMinute())) {
                    throw ValidationException::withMessages(['observed_at' => 'Use the actual UTC visit time, from request submission through now.']);
                }
                $selected = $this->select($fields['items'], $rows, $r->consumer_id);
                $visit = Str::uuid()->toString();
                $command = AdminMutation::command($actor, $action, $fields['idempotency_key'], $hash, $visit);
                $event = AdminMutation::event($actor, 'request', $id, $r->version + 1, 'physical_visit_recorded', $command, ['visit_id' => $visit, 'received' => count(array_filter($selected, fn ($s) => $s['outcome']['outcome'] === 'received')), 'attempted' => count($selected), 'untouched' => $rows->count() - count($selected)]);
                $party = $this->recipient($r, $actor);
                $attachment = $this->evidence($actor, $evidence, $stored);
                DB::table('receipt_visits')->insert(['id' => $visit, 'request_id' => $id, 'assignment_id' => $assignment?->id, 'actor_id' => $actor->id, 'recipient_party_id' => $party, 'command_id' => $command, 'event_id' => $event, 'request_version' => $r->version + 1, 'observed_at' => $observed, 'recorded_at' => now(), 'evidence_kind' => $fields['evidence_kind'], 'reference_ciphertext' => $fields['evidence_kind'] === 'operator_reference' ? Crypt::encryptString(json_encode(['issuer' => $fields['reference_issuer'], 'code' => $fields['reference_code'], 'provenance' => 'Receiving staff assertion; not independently verified', 'asserted_by' => $actor->id], JSON_THROW_ON_ERROR)) : null, 'attachment_id' => $attachment]);
                $selected = $this->outcomes($actor, $selected, $visit, $id);
                if ($route) {
                    DB::table('route_consumptions')->insert(['route_id' => $route->id, 'assignment_id' => $assignment->id, 'visit_id' => $visit]);
                }
                foreach ($selected as $s) {
                    if ($s['outcome']['outcome'] === 'received') {
                        $this->receive($actor, $visit, $command, $party, $observed, $s, $route !== null);
                    }
                }
                $this->closeWork($actor, $work, $visit, $command);
                DB::table('notification_outbox')->insert(['id' => Str::uuid()->toString(), 'event_id' => $event, 'recipient_id' => $r->consumer_id, 'channel' => 'in_app', 'state' => 'pending']);

                return $visit;
            });
        } catch (Throwable $e) {
            if ($stored && ! DB::table('attachments')->where('storage_key', $stored['storage_key'])->exists()) {
                Storage::disk('directory_evidence')->delete($stored['storage_key']);
            }
            if ($e instanceof QueryException && $e->getCode() === '23505' && str_contains($e->errorInfo[2] ?? '', '"first_receipts_pkey"')) {
                $this->conflict($actor, $id);
                abort(409, 'This physical item already has a first receipt. Reload current work; custody was not overridden.');
            }
            if ($e instanceof HttpException && $e->getStatusCode() === 409) {
                $this->conflict($actor, $id);
            }
            throw $e;
        }
    }

    private function lockWork(Context $context, User $actor, string $id): array
    {
        $ids = DB::table('request_items')->where('request_id', $id)->orderBy('item_id')->pluck('item_id');
        DB::table('subjects')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        DB::table('items')->whereIn('subject_id', $ids)->orderBy('subject_id')->lockForUpdate()->get();
        DB::table('requests')->where('id', $id)->lockForUpdate()->firstOrFail();
        DB::table('request_items')->where('request_id', $id)->orderBy('item_id')->lockForUpdate()->get();

        return $context->current($actor, $id);
    }

    private function select(array $outcomes, Collection $rows, string $owner): array
    {
        $selected = [];
        foreach ($outcomes as $outcome) {
            $row = $rows->firstWhere('id', $outcome['request_item_id']);
            abort_unless($row && hash_equals($row->public_code, $outcome['code']), 409, 'An item does not match the outstanding work. Recheck its label; no other record is disclosed.');
            $custody = DB::table('custody_current as c')->join('parties as p', 'p.id', '=', 'c.party_id')->where('c.subject_id', $row->item_id)->lockForUpdate()->first(['c.*', 'p.user_id', 'p.kind']);
            abort_unless($custody && $custody->kind === 'user' && $custody->user_id === $owner && ! DB::table('first_receipts')->where('subject_id', $row->item_id)->exists(), 409, 'An item has already been received or its custody changed. Administrator review is required; custody will not be overridden.');
            $selected[] = ['row' => $row, 'outcome' => $outcome, 'custody' => $custody];
        }

        return $selected;
    }

    private function evidence(User $actor, ?UploadedFile $evidence, ?array &$stored): ?string
    {
        $attachment = null;
        if ($evidence) {
            $stored = app(PrivateEvidence::class)->store($evidence);
            $attachment = Str::uuid()->toString();
            DB::table('attachments')->insert($stored + ['id' => $attachment, 'uploaded_by' => $actor->id, 'privacy_class' => 'private']);
            // The visit FK scopes staff evidence separately from owner-visible registration photos.
        }

        return $attachment;
    }

    private function outcomes(User $actor, array $selected, string $visit, string $id): array
    {
        foreach ($selected as &$s) {
            $outcomeId = Str::uuid()->toString();
            $s['outcome_id'] = $outcomeId;
            DB::table('receipt_outcomes')->insert(['id' => $outcomeId, 'visit_id' => $visit, 'request_item_id' => $s['row']->id, 'outcome' => $s['outcome']['outcome'], 'attested' => $s['outcome']['attested'], 'observation_ciphertext' => Crypt::encryptString($s['outcome']['observation'])]);
            if (in_array($s['outcome']['outcome'], ['mismatch', 'safety_hold'], true)) {
                DB::table('receipt_discrepancies')->insert(['id' => Str::uuid()->toString(), 'request_id' => $id, 'actor_id' => $actor->id, 'outcome_id' => $outcomeId, 'kind' => $s['outcome']['outcome'], 'recorded_at' => now()]);
            }
        }unset($s);

        return $selected;
    }

    private function closeWork(User $actor, array $work, string $visit, string $command): void
    {
        ['r' => $r,'assignment' => $assignment,'route' => $route] = $work;
        $id = $r->id;
        $outcomes = DB::table('request_items')->where('request_id', $id)->pluck('outcome');
        $state = $outcomes->contains('outstanding') ? ($outcomes->contains('collected') ? 'PARTIALLY_COMPLETED' : 'SUBMITTED') : ($outcomes->every(fn ($v) => $v === 'cancelled') ? 'CANCELLED' : 'COMPLETED');
        $changes = ['version' => $r->version + 1, 'state' => $state, 'review_state' => 'stale'];
        if ($route) {
            app(Execution::class)->change($visit, 'requests', $id, $changes);
        } else {
            DB::table('requests')->where('id', $id)->update($changes);
        }
        if ($assignment) {
            $changes = ['state' => $outcomes->contains('outstanding') ? 'remainder_review' : 'completed', 'active' => false, 'version' => $assignment->version + 1];
            app(Execution::class)->change($visit, 'assignments', $assignment->id, $changes);
            History::record($actor, DB::table('assignments')->where('id', $assignment->id)->first(), $command);
            DB::table('route_claims')->where('approval_id', $route->approval_id)->where('kind', 'assignment')->where('record_id', $assignment->id)->delete();
        }
    }

    private function recipient(object $r, User $actor): string
    {
        $field = $r->mode === 'pickup' ? 'user_id' : 'place_id';
        $ref = $r->mode === 'pickup' ? $actor->id : $r->point_id;
        $existing = DB::table('parties')->where($field, $ref)->value('id');
        if ($existing) {
            return $existing;
        }
        $id = Str::uuid()->toString();
        DB::table('parties')->insert(['id' => $id, 'kind' => $r->mode === 'pickup' ? 'user' : 'place', $field => $ref]);

        return $id;
    }

    private function receive(User $actor, string $visit, string $command, string $party, CarbonImmutable $observed, array $s, bool $routed): void
    {
        $row = $s['row'];
        $version = $row->subject_version + 1;
        $event = Str::uuid()->toString();
        DB::table('domain_events')->insert(['id' => $event, 'subject_id' => $row->item_id, 'aggregate_kind' => 'item', 'aggregate_id' => $row->item_id, 'aggregate_version' => $version, 'actor_id' => $actor->id, 'type' => 'first_receipt', 'occurred_at' => $observed, 'recorded_at' => now(), 'command_id' => $command, 'safe_payload' => json_encode(['visit_id' => $visit, 'outcome_id' => $s['outcome_id'], 'from_party_id' => $s['custody']->party_id, 'recipient_party_id' => $party, 'acknowledged' => true])]);
        DB::table('first_receipts')->insert(['subject_id' => $row->item_id, 'outcome_id' => $s['outcome_id'], 'event_id' => $event, 'from_party_id' => $s['custody']->party_id, 'recipient_party_id' => $party, 'actor_id' => $actor->id, 'subject_version' => $version, 'custody_version' => $s['custody']->version + 1]);
        if ($routed) {
            app(Execution::class)->change($visit, 'subjects', $row->item_id, ['version' => $version]);
            app(Execution::class)->change($visit, 'request_items', $row->id, ['outcome' => 'collected', 'active' => false]);
        } else {
            DB::table('subjects')->where('id', $row->item_id)->update(['version' => $version]);
            DB::table('request_items')->where('id', $row->id)->update(['outcome' => 'collected', 'active' => false]);
        }
        DB::table('items')->where('subject_id', $row->item_id)->update(['processing_state' => 'received']);
        DB::table('custody_current')->where('subject_id', $row->item_id)->update(['party_id' => $party, 'last_event_id' => $event, 'version' => $s['custody']->version + 1]);
    }

    private function conflict(User $actor, string $id): void
    {
        DB::transaction(function () use ($actor, $id) {
            Review::lock();
            $context = app(Context::class);
            try {
                $actor = $context->actor($actor);
                $context->authorized($actor, $id);
            } catch (HttpException) {
                return;
            }
            DB::table('receipt_discrepancies')->insert(['id' => Str::uuid()->toString(), 'request_id' => $id, 'actor_id' => $actor->id, 'kind' => 'late_conflict', 'recorded_at' => now()]);
        });
    }
}
