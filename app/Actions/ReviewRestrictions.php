<?php

namespace App\Actions;

use App\Enums\Role;
use App\Models\User;
use App\Support\Assignments\History;
use App\Support\Collection\AdminMutation;
use App\Support\Collection\Review;
use App\Support\Devices\Identifiers;
use App\Support\Restrictions\Context;
use App\Support\Restrictions\Input;
use App\Support\Restrictions\MediaEffects;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ReviewRestrictions
{
    public function open(User $actor, string $sourceKind, string $sourceId, string $fact, #[\SensitiveParameter] array $fields, ?UploadedFile $file = null): string
    {
        return $this->run($actor, null, 'open', [$sourceKind, $sourceId, $fact], $fields, $file);
    }

    public function record(User $actor, string $case, string $kind, #[\SensitiveParameter] array $fields, ?UploadedFile $file = null): string
    {
        abort_unless(isset(Context::LABELS[$kind]) && $kind !== 'open', 404);

        return $this->run($actor, $case, $kind, null, $fields, $file);
    }

    private function run(User $actor, ?string $caseId, string $kind, ?array $sourceRef, array $fields, ?UploadedFile $file): string
    {
        $stored = null;
        try {
            return DB::transaction(function () use ($actor, $caseId, $kind, $sourceRef, $fields, $file, &$stored) {
                Review::lock();
                $ctx = app(Context::class);
                $actor = $ctx->actor($actor);
                $c = $caseId ? $ctx->case($actor, $caseId) : null;
                abort_if($actor->role() === Role::Consumer && ! ($c && ($c->contract ?? 'C14') === 'P15' && $c->fact_key === 'return_report' && $kind === 'owner_clarify'), 403);
                abort_if(! $c && $actor->role() !== Role::Administrator, 403);
                $source = $c ? $ctx->source($c->source_kind, $c->source_id, $c->fact_key) : $ctx->source(...$sourceRef);
                $typed = $source->source_kind === 'media_correction';
                $fields = Input::validate($fields, $kind, $file, $typed ? $source->fact_key : null);
                $action = 'restriction_'.$kind.':'.($caseId ?? implode(':', $sourceRef));
                $hash = AdminMutation::hash($fields);
                if ($retry = AdminMutation::retry($actor, $action, $fields['idempotency_key'], $hash)) {
                    return $retry;
                }
                $family = DB::select('SELECT subject_id FROM restriction_family(?) ORDER BY subject_id', [$source->subject_id]);
                $ids = array_column($family, 'subject_id');
                if ($typed && $source->fact_key === 'discovery_supplement' && $kind === 'approve') {
                    $proposal = DB::table('restriction_records')->where('id', $fields['proposal_id'])->where('case_id', $c->id)->where('kind', 'proposal')->firstOrFail();
                    foreach (json_decode($proposal->details, true)['units'] ?? [] as $unit) {
                        if (! empty($unit['existing_id'])) {
                            $ids = array_merge($ids, array_column(DB::select('SELECT subject_id FROM restriction_family(?) ORDER BY subject_id', [$unit['existing_id']]), 'subject_id'));
                        }
                    }
                    $ids = array_values(array_unique($ids));
                    sort($ids);
                }
                DB::table('subjects')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
                DB::table('custody_current')->whereIn('subject_id', $ids)->orderBy('subject_id')->lockForUpdate()->get();
                if ($c) {
                    DB::table('restriction_cases')->where('id', $c->id)->lockForUpdate()->firstOrFail();
                    $c = $ctx->case($actor, $c->id);
                    if ($c->assigned_to) {
                        DB::table('users')->where('id', $c->assigned_to)->lockForUpdate()->firstOrFail();
                        $membership = DB::table('memberships')->where('user_id', $c->assigned_to)->lockForUpdate()->firstOrFail();
                        DB::table('places')->where('id', $membership->place_id)->lockForUpdate()->get();
                        DB::table('organizations')->where('id', $membership->organization_id)->lockForUpdate()->get();
                    }
                }
                abort_unless((int) ($c?->version ?? 0) === (int) $fields['expected_version'] && (int) $source->source_version === (int) $fields['expected_source_version'] && $ctx->epoch($source->subject_id) === (int) $fields['expected_restriction_version'] && hash_equals($ctx->token($actor, $source, $c), $fields['expected_context']), 409, 'Case, source or dependencies changed. Reload Operational restriction review and its impact preview.');
                $id = Str::uuid()->toString();
                $caseId = $c?->id ?? Str::uuid()->toString();
                $context = $ctx->dependencies($source->subject_id);
                if (! $c) {
                    DB::table('restriction_cases')->insert(['id' => $caseId, 'source_kind' => $source->source_kind, 'source_id' => $source->source_id, 'fact_key' => $source->fact_key, 'subject_id' => $source->subject_id, 'source_event_id' => $source->source_event_id, 'source_version' => $source->source_version, 'original_value' => $source->original_value, 'supported' => $source->supported, 'created_by' => $actor->id, 'created_at' => now(), 'state' => 'open', 'active' => true, 'version' => 1, 'record_id' => $id] + ($typed ? ['contract' => 'P15'] : []));
                }
                $command = AdminMutation::command($actor, $action, $fields['idempotency_key'], $hash, $caseId);
                $observed = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $fields['observed_at'], 'UTC')->startOfMinute();
                $recorded = now();
                $event = Str::uuid()->toString();
                $milestone = match (true) {
                    $kind === 'fulfill' => 'Current obligation fulfillment recorded; independent gates and later changes still apply. No physical outcome recorded.',
                    $kind === 'owner_clarify' => 'Owner report clarification recorded. This is not receipt or transfer.',
                    $typed && $kind === 'approve' && $source->fact_key === 'discovery_supplement' => 'On-site provenance supplement recorded; accounting and data obligations remain separate. No physical transfer recorded.',
                    $typed && $kind === 'approve' && $source->fact_key === 'impact_continuity' => 'Obligation continuity recorded; fresh revalidation and separate fulfillment are required. No physical authority granted.',
                    $kind === 'approve' => 'Recorded fact superseded; fresh review required. No physical outcome recorded.',
                    default => 'Investigation updated; restriction unchanged.',
                };
                DB::table('domain_events')->insert(['id' => $event, 'subject_id' => null, 'aggregate_kind' => 'restriction', 'aggregate_id' => $caseId, 'aggregate_version' => ($c?->version ?? 0) + 1, 'actor_id' => $actor->id, 'command_id' => $command, 'type' => 'restriction_'.$kind, 'occurred_at' => $observed, 'recorded_at' => $recorded, 'safe_payload' => json_encode(['case_id' => $caseId, 'milestone' => $milestone])]);
                $evidence = $fields['evidence_kind'] === 'none' ? ['attachment_id' => null, 'reference_ciphertext' => null] : app(DismantleBatches::class)->evidence($actor, $fields, $file, $stored);
                $details = $fields['details'] ?? [];
                if ($typed && $source->fact_key === 'return_report' && $kind === 'proposal') {
                    $details['owner_statement_id'] = DB::table('restriction_records')->where('case_id', $caseId)->where('kind', 'owner_clarify')->orderByDesc('version')->value('id');
                }
                $typedFields = $typed ? ['details' => json_encode(app(MediaEffects::class)->prepare($details, $actor, $source->subject_id), JSON_THROW_ON_ERROR), 'private_details_ciphertext' => $details ? Crypt::encryptString(json_encode($details, JSON_THROW_ON_ERROR)) : null] : [];
                DB::table('restriction_records')->insert($typedFields + ['id' => $id, 'case_id' => $caseId, 'version' => ($c?->version ?? 0) + 1, 'predecessor_id' => $c?->record_id, 'kind' => $kind, 'proposal_id' => $fields['proposal_id'] ?? null, 'grant_record_id' => $kind === 'proposal' ? $c->grant_record_id : null, 'assigned_to' => $fields['assigned_to'] ?? null, 'pre_receipt' => (bool) ($fields['pre_receipt'] ?? false), 'allowed_evidence' => '{'.implode(',', $fields['allowed_evidence'] ?? []).'}', 'original_value' => $fields['original_value'] ?? null, 'proposed_value' => $fields['proposed_value'] ?? null, 'identity_code' => $fields['identity_code'] ?? null, 'checks' => json_encode($fields['checks'] ?? new \stdClass), 'basis_ciphertext' => isset($fields['basis']) ? Crypt::encryptString($fields['basis']) : null, 'source_version' => $source->source_version, 'restriction_version' => $ctx->epoch($source->subject_id), 'context' => json_encode($context, JSON_THROW_ON_ERROR), 'actor_context' => json_encode($ctx->actorContext($actor->id), JSON_THROW_ON_ERROR), 'actor_id' => $actor->id, 'command_id' => $command, 'event_id' => $event, 'observed_at' => $observed, 'recorded_at' => $recorded, 'reason_ciphertext' => Crypt::encryptString($fields['reason'])] + $evidence);
                if ($typed && in_array($kind, ['approve', 'fulfill'], true)) {
                    $review = DB::table('restriction_records')->where('id', $id)->firstOrFail();
                    app(MediaEffects::class)->apply($actor, $c, $review);
                }
                if ($c) {
                    $projection = ['version' => $c->version + 1, 'record_id' => $id, 'state' => match ($kind) {
                        'approve' => 'superseded', 'close' => 'closed_no_change', default => 'under_review'
                    }, 'active' => ! in_array($kind, ['approve', 'close'], true)];
                    if ($typed) {
                        $state = DB::selectOne('SELECT media_case_state(c,r) AS state FROM restriction_cases c JOIN restriction_records r ON r.id=? WHERE c.id=?', [$id, $caseId])->state;
                        $projection['state'] = $state;
                        $projection['active'] = in_array($state, ['open', 'under_review', 'revalidation_pending'], true);
                    }
                    if ($kind === 'assign') {
                        $projection += ['assigned_to' => $fields['assigned_to'], 'grant_record_id' => $id, 'assignment_context' => json_encode($ctx->actorContext($fields['assigned_to'])), 'pre_receipt' => (bool) $fields['pre_receipt'], 'allowed_evidence' => '{'.implode(',', $fields['allowed_evidence'] ?? []).'}'];
                    } elseif ($kind === 'revoke') {
                        $projection += ['assigned_to' => null, 'grant_record_id' => null, 'assignment_context' => null, 'pre_receipt' => false, 'allowed_evidence' => '{}'];
                    }
                    DB::table('restriction_cases')->where('id', $caseId)->update($projection);
                }
                if ($kind === 'approve' && (! $typed || $source->fact_key !== 'impact_continuity')) {
                    $this->invalidate($actor, $ids, $command, $caseId);
                }
                $this->notify($actor, $caseId, $event);
                if ($typed && in_array($kind, ['approve', 'fulfill'], true)) {
                    DB::table('media_review_commits')->insert(['record_id' => $id, 'context' => json_encode($ctx->dependencies($source->subject_id), JSON_THROW_ON_ERROR)]);
                }

                return $caseId;
            });
        } catch (Throwable $e) {
            app(DismantleBatches::class)->cleanup($stored);
            if ($e instanceof \PDOException && in_array($e->errorInfo[0] ?? null, ['P1400', 'P1500'], true) && preg_match('/ERROR:\s*([^\n]+)/', $e->errorInfo[2] ?? '', $match)) {
                throw ValidationException::withMessages(['submission' => $match[1]]);
            }
            if ($e instanceof QueryException && Identifiers::isDuplicateConflict($e)) {
                throw ValidationException::withMessages(['details.units' => Identifiers::DUPLICATE_MESSAGE]);
            }
            if ($e instanceof \PDOException && ($e->errorInfo[0] ?? null) === '23505' && str_contains($e->errorInfo[2] ?? '', 'restriction_one_active_case')) {
                abort(409, 'An active investigation already covers this exact source fact. Reload the queue.');
            }
            throw $e;
        }
    }

    private function invalidate(User $actor, array $subjects, string $command, string $case): void
    {
        $requests = DB::table('requests')->whereExists(fn ($q) => $q->selectRaw('1')->from('request_items')->whereColumn('request_id', 'requests.id')->whereIn('item_id', $subjects)->where('active', true))->orderBy('id')->lockForUpdate()->get();
        foreach ($requests as $request) {
            DB::table('requests')->where('id', $request->id)->update(['version' => $request->version + 1, 'review_state' => 'stale']);
            AdminMutation::event($actor, 'request', $request->id, $request->version + 1, 'request_restriction_corrected', $command, ['case_id' => $case, 'review_required' => true]);
            History::invalidate($actor, $request->id, $command, 'restriction_fact_superseded');
        }
    }

    private function notify(User $actor, string $case, string $event): void
    {
        $c = DB::table('restriction_cases')->where('id', $case)->firstOrFail();
        $owner = DB::table('items')->where('subject_id', $c->subject_id)->value('owner_user_id');
        foreach (array_unique(array_filter([$actor->id, $c->created_by, $c->assigned_to, $owner])) as $recipient) {
            DB::table('notification_outbox')->insert(['id' => Str::uuid()->toString(), 'event_id' => $event, 'recipient_id' => $recipient, 'channel' => 'in_app', 'state' => 'pending']);
        }
    }
}
