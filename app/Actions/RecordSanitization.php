<?php

namespace App\Actions;

use App\Models\User;
use App\Support\Collection\AdminMutation;
use App\Support\Collection\PrivateEvidence;
use App\Support\Collection\Review;
use App\Support\Sanitization\Context;
use App\Support\Sanitization\Input;
use App\Support\Sanitization\Policy;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class RecordSanitization
{
    public function record(User $actor, string $subject, string $kind, #[\SensitiveParameter] array $fields, #[\SensitiveParameter] ?UploadedFile $file = null): string
    {
        abort_unless(in_array($kind, ['attempt', 'verification', 'validation', 'revalidation'], true), 404);
        $stored = null;
        try {
            return DB::transaction(function () use ($actor, $subject, $kind, $fields, $file, &$stored) {
                Review::lock();
                $actor = app(\App\Support\Receipts\Context::class)->actor($actor);
                $inspection = app(\App\Support\Assessments\Context::class);
                $row = $inspection->authorized($actor, $subject);
                $fields = Input::validate($fields, $kind, $file);
                $action = 'sanitization_'.$kind.':'.$subject;
                $hash = AdminMutation::hash($fields);
                if ($retry = AdminMutation::retry($actor, $action, $fields['idempotency_key'], $hash)) {
                    return $retry;
                }
                foreach (['subjects' => 'id', 'items' => 'subject_id', 'custody_current' => 'subject_id', 'assessment_current' => 'subject_id'] as $table => $key) {
                    DB::table($table)->where($key, $subject)->lockForUpdate()->first();
                }
                DB::table('sanitization_current')->where('subject_id', $subject)->lockForUpdate()->first();
                $ctx = app(Context::class);
                $row = $inspection->authorized($actor, $subject);
                if ($blockers = $ctx->blockers($row, $actor, $kind)) {
                    throw ValidationException::withMessages(['submission' => implode(' ', $blockers)]);
                }
                $current = $ctx->current($subject);
                abort_unless((int) ($current?->version ?? 0) === (int) $fields['expected_version'] && hash_equals($ctx->token($actor, $row), $fields['expected_context']), 409, 'Media, inspection, custody, access or assurance changed. Reload current history and open a fresh form; nothing was recorded.');
                abort_unless(isset($ctx->actions($current)[$kind]), 409, 'This assurance step is no longer available. Follow the next action in current history; negative evidence requires a new attempt.');
                $policy = DB::table('sanitization_policies')->where('id', Policy::ID)->firstOrFail();
                if (! hash_equals(Policy::checksum(), $policy->checksum)) {
                    throw new \RuntimeException('Frozen sanitization policy differs from this application; no record saved.');
                }
                $this->assurance($kind, $fields, $current);
                $observed = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $fields['observed_at'], 'UTC')->startOfMinute();
                $earliest = CarbonImmutable::parse($current?->observed_at ?? DB::table('domain_events')->where('id', $row->last_event_id)->value('occurred_at'))->startOfMinute();
                if ($observed->isFuture() || $observed->lt($earliest)) {
                    throw ValidationException::withMessages(['observed_at' => 'Enter actual UTC time from the latest custody/assurance event through now. Explain retrospective entry in the private reason.']);
                }
                $id = Str::uuid()->toString();
                $command = AdminMutation::command($actor, $action, $fields['idempotency_key'], $hash, $id);
                $event = Str::uuid()->toString();
                $recorded = now();
                $technique = $kind === 'attempt' ? $fields['technique'] : $current->technique;
                $record = ['id' => $id, 'subject_id' => $subject, 'version' => ($current?->version ?? 0) + 1, 'predecessor_id' => $current?->id, 'attempt_id' => $kind === 'attempt' ? $id : $current->attempt_id, 'previous_attempt_id' => $kind === 'attempt' ? $current?->attempt_id : null, 'kind' => $kind, 'outcome' => $fields['outcome'], 'technique' => $technique, 'policy_id' => Policy::ID, 'actor_id' => $actor->id, 'command_id' => $command, 'event_id' => $event, 'observed_at' => $observed, 'recorded_at' => $recorded, 'reason_ciphertext' => Crypt::encryptString($fields['reason']), 'checks' => json_encode((object) $fields['checks'], JSON_THROW_ON_ERROR)] + $ctx->snapshot($actor, $row);
                foreach (['executor', 'tool', 'tool_version', 'scope', 'procedure'] as $name) {
                    $record[$name.'_ciphertext'] = $kind === 'attempt' && filled($fields[$name]) ? Crypt::encryptString($fields[$name]) : null;
                }
                $record += $this->evidence($actor, $fields, $file, $stored);
                DB::table('domain_events')->insert(['id' => $event, 'subject_id' => $subject, 'aggregate_kind' => 'sanitization', 'aggregate_id' => $subject, 'aggregate_version' => $record['version'], 'actor_id' => $actor->id, 'type' => 'sanitization_'.$kind, 'occurred_at' => $observed, 'recorded_at' => $recorded, 'command_id' => $command, 'safe_payload' => json_encode(['record_id' => $id, 'milestone' => 'Media treatment '.$kind.' recorded; no release or custody change.'])]);
                DB::table('sanitization_records')->insert($record);
                $projection = ['version' => $record['version'], 'record_id' => $id, 'attempt_id' => $record['attempt_id'], 'destruction_reported' => ($current?->destruction_reported ?? false) || Policy::definition()['techniques'][$technique]['method'] === 'destroy'];
                if ($current) {
                    DB::table('sanitization_current')->where('subject_id', $subject)->update($projection);
                } else {
                    DB::table('sanitization_current')->insert(['subject_id' => $subject] + $projection);
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

    private function assurance(string $kind, #[\SensitiveParameter] array $fields, ?object $current): void
    {
        if ($kind === 'attempt' || ! in_array($fields['outcome'], ['satisfactory', 'accepted'], true)) {
            return;
        }
        if (array_filter($fields['checks'], fn ($value) => $value !== 'YES')) {
            throw ValidationException::withMessages(['outcome' => 'Positive assurance requires every applicable check YES. Record uncertainty or rejection honestly; do not assume missing facts.']);
        }
        $supported = DB::selectOne('SELECT sanitization_supported(r) AS supported FROM sanitization_records r WHERE id=?', [$current->attempt_id])->supported;
        if (! $supported) {
            throw ValidationException::withMessages(['outcome' => 'This technique/media, completion metadata or retained destruction concern cannot support positive assurance under MEDIA-ASSURANCE-1.0. Record a negative review or a new appropriate external attempt.']);
        }
    }

    private function evidence(User $actor, #[\SensitiveParameter] array $fields, #[\SensitiveParameter] ?UploadedFile $file, ?array &$stored): array
    {
        $attachment = null;
        if ($file) {
            $stored = app(PrivateEvidence::class)->store($file);
            $attachment = Str::uuid()->toString();
            DB::table('attachments')->insert($stored + ['id' => $attachment, 'uploaded_by' => $actor->id, 'privacy_class' => 'private']);
        }

        return ['evidence_kind' => $fields['evidence_kind'], 'attachment_id' => $attachment, 'reference_ciphertext' => $fields['evidence_kind'] === 'operator_reference' ? Crypt::encryptString(json_encode(['issuer' => $fields['reference_issuer'], 'code' => $fields['reference_code'], 'asserted_by' => $actor->id, 'provenance' => 'Accountable staff assertion; not independently verified'], JSON_THROW_ON_ERROR)) : null];
    }
}
