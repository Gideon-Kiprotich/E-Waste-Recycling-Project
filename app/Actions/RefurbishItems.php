<?php

namespace App\Actions;

use App\Enums\Role;
use App\Models\User;
use App\Support\Collection\AdminMutation;
use App\Support\Collection\PrivateEvidence;
use App\Support\Collection\Review;
use App\Support\Refurbishment\Context;
use App\Support\Refurbishment\Input;
use App\Support\Refurbishment\Profiles;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class RefurbishItems
{
    public function record(User $actor, string $subject, string $kind, #[\SensitiveParameter] array $fields, ?UploadedFile $file = null): string
    {
        abort_unless(isset(Context::LABELS[$kind]), 404);
        $stored = null;
        try {
            return DB::transaction(function () use ($actor, $subject, $kind, $fields, $file, &$stored) {
                Review::lock();
                $actor = $this->actor($actor, $kind);
                $ctx = app(Context::class);
                $item = $ctx->item($actor, $subject);
                $fields = Input::validate($fields, $kind, $item, $file);
                $action = 'refurbishment_'.$kind.':'.$subject;
                $hash = AdminMutation::hash(['subject_id' => $subject] + $fields);
                if ($retry = AdminMutation::retry($actor, $action, $fields['idempotency_key'], $hash)) {
                    return $retry;
                }
                $ids = DB::table('media_inventory_entries')->where('parent_id', $subject)->whereNotNull('medium_id')->pluck('medium_id')->push($subject)->sort()->all();
                foreach (['subjects' => 'id', 'items' => 'subject_id', 'custody_current' => 'subject_id', 'assessment_current' => 'subject_id', 'sanitization_current' => 'subject_id', 'media_authorization_current' => 'subject_id'] as $table => $key) {
                    DB::table($table)->whereIn($key, $ids)->orderBy($key)->lockForUpdate()->get();
                }
                $cur = DB::table('refurbishment_current')->where('subject_id', $subject)->lockForUpdate()->first();
                abort_unless($ctx->version($subject) === (int) $fields['expected_version'] && hash_equals($ctx->token($actor, $subject), $fields['expected_context']), 409, 'Work, tests, media, custody or access changed. Reload Refurbishment / Owner return and open a fresh form.');
                $authorization = isset($fields['authorization_id']) ? DB::table('reuse_authorizations')->where('id', $fields['authorization_id'])->where('subject_id', $subject)->lockForUpdate()->first() : null;
                $snapshot = $ctx->snapshot($subject, $actor->id);
                if (in_array($kind, ['acknowledge', 'exception', 'withdraw'], true)) {
                    abort_unless($authorization && $authorization->state === 'pending', 409, 'This authorization is no longer pending. Nothing was transferred.');
                }
                if ($kind === 'acknowledge') {
                    $original = DB::table('refurbishment_records')->where('id', $authorization->id)->firstOrFail();
                    $snapshot = json_decode($original->context, true, flags: JSON_THROW_ON_ERROR);
                    if ($snapshot !== $ctx->snapshot($subject, $original->actor_id)) {
                        throw ValidationException::withMessages(['submission' => 'Authorization is stale. Ask the facility to withdraw it, resolve the displayed blockers and authorize a fresh return. Restoring old eligibility does not revive it.']);
                    }
                    $this->eligible($ctx, $subject, $original->actor_id, true);
                    $expected = $ctx->manifest($authorization->id)->pluck('subject_id')->sort()->values()->all();
                    if ($fields['received'] !== $expected) {
                        throw ValidationException::withMessages(['received' => 'Acknowledge every displayed item only after actual complete receipt. Otherwise use Report incomplete or mismatched return; no partial custody transfer is supported.']);
                    }
                } elseif (! in_array($kind, ['withdraw', 'exception'], true)) {
                    $this->eligible($ctx, $subject, $actor->id, $kind === 'authorize', in_array($kind, ['test', 'work_applicability', 'fan_na'], true) ? $kind : 'progress');
                }
                $this->validateStep($subject, $kind, $fields, $snapshot);
                $observed = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $fields['observed_at'], 'UTC')->startOfMinute();
                $earliest = DB::table('refurbishment_records')->where('id', $cur?->record_id)->value('observed_at') ?? DB::table('custody_current as cc')->join('domain_events as e', 'e.id', '=', 'cc.last_event_id')->where('cc.subject_id', $subject)->value('e.occurred_at');
                if ($observed->isFuture() || $observed->lt(CarbonImmutable::parse($earliest)->startOfMinute())) {
                    throw ValidationException::withMessages(['observed_at' => 'Enter actual UTC time from the latest custody/workflow event through now. Explain retrospective entry in the private reason.']);
                }
                $id = Str::uuid()->toString();
                $command = AdminMutation::command($actor, $action, $fields['idempotency_key'], $hash, $id);
                $event = Str::uuid()->toString();
                $result = match ($kind) {
                    'work_applicability' => 'applicable', 'work' => $fields['outcome'], 'test' => Profiles::result($fields['answers']), 'fan_na' => 'not_applicable', 'authorize' => 'pending', 'withdraw' => 'withdrawn', 'exception' => 'exception', 'acknowledge' => 'acknowledged',
                };
                $record = ['id' => $id, 'subject_id' => $subject, 'version' => ($cur?->version ?? 0) + 1, 'predecessor_id' => $cur?->record_id, 'kind' => $kind, 'result' => $result, 'configuration' => $kind === 'work_applicability' ? 'UNCHANGED' : ($fields['configuration'] ?? null), 'profile_version' => $kind === 'test' ? Profiles::VERSION : null, 'answers' => json_encode($kind === 'acknowledge' ? array_fill_keys($fields['received'], 'RECEIVED') : (object) $fields['answers'], JSON_THROW_ON_ERROR), 'na_reasons_ciphertext' => empty(array_filter($fields['na_reasons'] ?? [])) ? null : Crypt::encryptString(json_encode($fields['na_reasons'], JSON_THROW_ON_ERROR)), 'work_id' => $kind === 'work_applicability' ? $fields['work_id'] : ($kind === 'test' ? $snapshot['work_id'] : null), 'authorization_id' => $fields['authorization_id'] ?? null, 'context' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'actor_id' => $actor->id, 'command_id' => $command, 'event_id' => $event, 'observed_at' => $observed, 'recorded_at' => now(), 'reason_ciphertext' => Crypt::encryptString($fields['reason'])] + $this->evidence($actor, $fields, $file, $stored);
                if ($kind === 'work_applicability') {
                    $record['correction_review_id'] = $fields['correction_review_id'];
                }
                DB::table('domain_events')->insert($this->event($record, $subject, 'refurbishment', $record['version'], 'refurbishment_'.$kind, $event, $kind === 'acknowledge' ? 'Owner acknowledged complete reuse return.' : Context::LABELS[$kind].' recorded; no custody transfer.'));
                DB::table('refurbishment_records')->insert($record);
                if ($kind === 'authorize') {
                    DB::table('reuse_authorizations')->insert(['id' => $id, 'subject_id' => $subject, 'state' => 'pending', 'record_id' => $id]);
                    foreach ($snapshot['configuration']['manifest'] as $m) {
                        DB::table('reuse_manifest')->insert(['authorization_id' => $id] + array_intersect_key($m, array_flip(['subject_id', 'owner_id', 'party_id', 'event_id', 'custody_version', 'subject_version'])));
                    }
                } elseif ($authorization) {
                    DB::table('reuse_authorizations')->where('id', $authorization->id)->update(['state' => $result, 'record_id' => $id]);
                    if ($kind === 'acknowledge') {
                        $this->returnManifest($record, $authorization->id, $actor);
                    }
                }
                $projection = ['version' => $record['version'], 'record_id' => $id];
                if ($cur) {
                    DB::table('refurbishment_current')->where('subject_id', $subject)->update($projection);
                } else {
                    DB::table('refurbishment_current')->insert(['subject_id' => $subject] + $projection);
                }

                return $id;
            });
        } catch (Throwable $e) {
            if ($stored && ! DB::table('attachments')->where('storage_key', $stored['storage_key'])->exists()) {
                Storage::disk('directory_evidence')->delete($stored['storage_key']);
            }
            throw $e;
        }
    }

    private function actor(User $actor, string $kind): User
    {
        $locked = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
        $locked->setRelation('membership', $locked->membership()->lockForUpdate()->first());
        $role = in_array($kind, ['acknowledge', 'exception'], true) ? Role::Consumer : Role::Processor;
        abort_unless($locked->canSignIn() && $locked->role() === $role && $locked->session_version === $actor->session_version, 403);

        return $locked;
    }

    private function eligible(Context $ctx, string $subject, string $staff, bool $release, string $operation = 'progress'): void
    {
        if ($messages = $ctx->blockers($subject, $staff, $release, $operation)) {
            throw ValidationException::withMessages(['submission' => implode(' ', $messages)]);
        }
    }

    private function validateStep(string $subject, string $kind, array $fields, array $snapshot): void
    {
        if ($kind === 'test') {
            $definition = json_encode(Profiles::catalog(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (! hash_equals(hash('sha256', $definition), DB::table('refurbishment_profiles')->where('version', Profiles::VERSION)->value('checksum') ?? '')) {
                throw new \RuntimeException('Frozen functional profile differs from application; no test recorded.');
            }
            $work = DB::table('refurbishment_records')->where('id', $snapshot['work_id'])->first();
            if (! $work || ! in_array($work->kind, ['work', 'work_applicability'], true) || ! DB::selectOne('SELECT refurbishment_work_ready(?) AS ready', [$subject])->ready) {
                throw ValidationException::withMessages(['submission' => 'Record applicable completed work with unchanged configuration before testing. A failed/incomplete result first needs fresh inspection and decision.']);
            }
        } elseif ($kind === 'authorize' && DB::table('reuse_authorizations')->where('subject_id', $subject)->where('state', 'pending')->exists()) {
            throw ValidationException::withMessages(['submission' => 'Withdraw the existing pending authorization before creating another, including after it becomes stale.']);
        } elseif ($kind === 'fan_na' && ! DB::table('assessment_current as ac')->join('assessments as a', 'a.id', '=', 'ac.assessment_id')->join('items as i', 'i.subject_id', '=', 'ac.subject_id')->where('ac.subject_id', $subject)->where('i.subtype', 'ict_cooling_fan')->where('a.data_status', 'NO')->exists()) {
            throw ValidationException::withMessages(['submission' => 'Fan N/A requires current qualifying basic-fan technician findings establishing NO and explicit private justification/evidence.']);
        }
    }

    private function returnManifest(array $record, string $authorization, User $owner): void
    {
        $party = DB::table('parties')->where('kind', 'user')->where('user_id', $owner->id)->value('id');
        abort_unless($party, 409, 'The existing owner party is unavailable; no return was recorded.');
        foreach (DB::table('reuse_manifest')->where('authorization_id', $authorization)->orderBy('subject_id')->get() as $m) {
            $event = Str::uuid()->toString();
            DB::table('domain_events')->insert($this->event($record, $m->subject_id, 'item', $m->subject_version + 1, 'reuse_returned', $event, 'Returned for reuse to the existing owner; ownership unchanged.'));
            DB::table('reuse_return_items')->insert(['subject_id' => $m->subject_id, 'authorization_id' => $authorization, 'record_id' => $record['id'], 'event_id' => $event, 'recipient_party_id' => $party, 'custody_version' => $m->custody_version + 1, 'subject_version' => $m->subject_version + 1]);
            DB::table('items')->where('subject_id', $m->subject_id)->update(['processing_state' => 'released']);
            DB::table('subjects')->where('id', $m->subject_id)->update(['version' => $m->subject_version + 1]);
            DB::table('custody_current')->where('subject_id', $m->subject_id)->update(['party_id' => $party, 'last_event_id' => $event, 'version' => $m->custody_version + 1]);
        }
    }

    private function event(array $r, string $subject, string $aggregate, int $version, string $type, string $event, string $milestone): array
    {
        return ['id' => $event, 'subject_id' => $subject, 'aggregate_kind' => $aggregate, 'aggregate_id' => $subject, 'aggregate_version' => $version, 'actor_id' => $r['actor_id'], 'command_id' => $r['command_id'], 'type' => $type, 'occurred_at' => $r['observed_at'], 'recorded_at' => $r['recorded_at'], 'safe_payload' => json_encode(['record_id' => $r['id'], 'milestone' => $milestone])];
    }

    private function evidence(User $actor, array $fields, ?UploadedFile $file, ?array &$stored): array
    {
        if ($file) {
            $stored = app(PrivateEvidence::class)->store($file);
            $id = Str::uuid()->toString();
            DB::table('attachments')->insert($stored + ['id' => $id, 'uploaded_by' => $actor->id, 'privacy_class' => 'private']);

            return ['attachment_id' => $id, 'reference_ciphertext' => null];
        }

        return ['attachment_id' => null, 'reference_ciphertext' => Crypt::encryptString(json_encode(['issuer' => $fields['reference_issuer'], 'code' => $fields['reference_code'], 'provenance' => 'Accountable actor assertion; not independent certification'], JSON_THROW_ON_ERROR))];
    }
}
