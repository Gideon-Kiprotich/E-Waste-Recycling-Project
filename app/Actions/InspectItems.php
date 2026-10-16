<?php

namespace App\Actions;

use App\Models\User;
use App\Support\Assessments\Context;
use App\Support\Assessments\Evaluator;
use App\Support\Assessments\Input;
use App\Support\Assessments\Schemas;
use App\Support\Collection\AdminMutation;
use App\Support\Collection\PrivateEvidence;
use App\Support\Collection\Review;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class InspectItems
{
    public function inspect(User $actor, string $subject, #[\SensitiveParameter] array $fields, #[\SensitiveParameter] ?UploadedFile $file = null): string
    {
        return $this->run($actor, $subject, $fields, false, $file);
    }

    public function decide(User $actor, string $subject, #[\SensitiveParameter] array $fields): string
    {
        return $this->run($actor, $subject, $fields, true, null);
    }

    private function run(User $actor, string $subject, #[\SensitiveParameter] array $fields, bool $decision, #[\SensitiveParameter] ?UploadedFile $file): string
    {
        $stored = null;
        try {
            return DB::transaction(function () use ($actor, $subject, $fields, $decision, $file, &$stored) {
                Review::lock();
                $actor = app(\App\Support\Receipts\Context::class)->actor($actor);
                $ctx = app(Context::class);
                $row = $ctx->authorized($actor, $subject);
                $schema = Schemas::definition($row->category, $row->subtype);
                $fields = Input::validate($fields, $schema, $file, $decision);
                $action = ($decision ? 'pathway_decision:' : 'inspect_item:').$subject;
                $hash = AdminMutation::hash($fields);
                if ($retry = AdminMutation::retry($actor, $action, $fields['idempotency_key'], $hash)) {
                    return $retry;
                }
                DB::table('subjects')->where('id', $subject)->lockForUpdate()->firstOrFail();
                DB::table('items')->where('subject_id', $subject)->lockForUpdate()->firstOrFail();
                DB::table('custody_current')->where('subject_id', $subject)->lockForUpdate()->firstOrFail();
                $row = $ctx->authorized($actor, $subject);
                $current = DB::table('assessment_current')->where('subject_id', $subject)->lockForUpdate()->first();
                if ($blockers = $ctx->blockers($row)) {
                    throw ValidationException::withMessages(['submission' => implode(' ', $blockers)]);
                }
                abort_unless((int) ($current?->version ?? 0) === (int) $fields['expected_version'] && (int) $row->subject_version === (int) $fields['expected_subject_version'], 409, 'Inspection or decision changed. Reload the current history and start a fresh submission; nothing was recorded.');
                $observed = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $fields['observed_at'], 'UTC')->startOfMinute();
                $earliest = CarbonImmutable::parse(DB::table('domain_events')->where('id', $row->last_event_id)->value('occurred_at'))->startOfMinute();
                if ($current) {
                    $prior = DB::table('inspection_steps')->where('subject_id', $subject)->orderByDesc('version')->value('observed_at');
                    $earliest = $earliest->max(CarbonImmutable::parse($prior)->startOfMinute());
                }
                if ($observed->isFuture() || $observed->lt($earliest)) {
                    throw ValidationException::withMessages(['observed_at' => 'Use actual UTC time from the latest receipt/inspection/decision through now. Explain retrospective entry in the private reason.']);
                }
                $id = Str::uuid()->toString();
                $command = AdminMutation::command($actor, $action, $fields['idempotency_key'], $hash, $id);
                $event = Str::uuid()->toString();
                $op = ['id' => $id, 'subject_id' => $subject, 'version' => ($current?->version ?? 0) + 1, 'source_subject_version' => $row->subject_version, 'subject_version' => $row->subject_version + 1, 'facility_party_id' => $row->party_id, 'custody_event_id' => $row->last_event_id, 'custody_version' => $row->custody_version, 'actor_id' => $actor->id, 'command_id' => $command, 'event_id' => $event, 'observed_at' => $observed, 'recorded_at' => now(), 'reason_ciphertext' => Crypt::encryptString($fields['reason'])];
                DB::table('domain_events')->insert(['id' => $event, 'subject_id' => $subject, 'aggregate_kind' => 'item', 'aggregate_id' => $subject, 'aggregate_version' => $op['subject_version'], 'actor_id' => $actor->id, 'type' => $decision ? 'pathway_decided' : 'inspection_recorded', 'occurred_at' => $observed, 'recorded_at' => now(), 'command_id' => $command, 'safe_payload' => json_encode(['record_id' => $id, 'milestone' => $decision ? 'Pathway decision recorded; no work or release recorded.' : 'Technician inspection recorded; awaiting a separate decision.'])]);
                $assessment = $decision ? DB::table('assessments')->where('id', $fields['assessment_id'])->where('subject_id', $subject)->first() : null;
                if ($decision) {
                    abort_unless($assessment && $current?->assessment_id === $assessment->id, 409, 'A fresh decision must reference the latest inspection. Reload its recommendation.');
                    if ($fields['pathway'] !== 'hold' && $ctx->restricted($subject)) {
                        throw ValidationException::withMessages(['pathway' => 'A recorded safety/identity/scope restriction requires separately reviewed correction. Only HOLD can be recorded; reassessment or override cannot clear it.']);
                    }
                    DB::table('decisions')->insert($op + ['assessment_id' => $assessment->id, 'pathway' => $fields['pathway'], 'override' => $fields['pathway'] !== $assessment->recommendation]);
                    $projection = ['version' => $op['version'], 'assessment_id' => $assessment->id, 'decision_id' => $id];
                } else {
                    $this->assessment($row, $schema, $fields, $op, $file, $stored);
                    $projection = ['version' => $op['version'], 'assessment_id' => $id, 'decision_id' => null];
                }
                if ($current) {
                    DB::table('assessment_current')->where('subject_id', $subject)->update($projection);
                } else {
                    DB::table('assessment_current')->insert(['subject_id' => $subject] + $projection);
                }
                DB::table('items')->where('subject_id', $subject)->update(['processing_state' => 'assessed']);
                DB::table('subjects')->where('id', $subject)->update(['version' => $op['subject_version']]);
                foreach (array_unique([$actor->id, $row->owner_user_id]) as $uid) {
                    DB::table('notification_outbox')->insert(['id' => Str::uuid()->toString(), 'event_id' => $event, 'recipient_id' => $uid, 'channel' => 'in_app', 'state' => 'pending']);
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

    private function assessment(object $row, array $schema, array $fields, array $op, ?UploadedFile $file, ?array &$stored): void
    {
        $code = $row->category === 'standalone_part' ? 'PARTS-ASSESS-1.0' : 'ASSESS-1.0';
        $set = DB::table('rule_sets')->where('version_code', $code)->firstOrFail();
        if (! hash_equals(Schemas::checksum(Schemas::catalog()[$code]), $set->checksum)) {
            throw new \RuntimeException('Frozen rule definition differs from this evaluator; no inspection recorded.');
        }
        $result = app(Evaluator::class)->evaluate($schema, $fields['answers'], $fields['na_reasons'], $fields['reason'], true);
        $attachment = null;
        if ($file) {
            $stored = app(PrivateEvidence::class)->store($file);
            $attachment = Str::uuid()->toString();
            DB::table('attachments')->insert($stored + ['id' => $attachment, 'uploaded_by' => $op['actor_id'], 'privacy_class' => 'private']);
        }
        DB::table('assessments')->insert($op + ['rule_set_id' => $set->id, 'schema_version' => $set->schema_version, 'rule_checksum' => $set->checksum, 'recommendation' => $result['recommendation'], 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'hard_hold' => $result['hard_hold'], 'data_status' => $result['data_status'], 'evidence_kind' => $fields['evidence_kind'], 'attachment_id' => $attachment, 'reference_ciphertext' => $file ? null : Crypt::encryptString(json_encode(['issuer' => $fields['reference_issuer'], 'code' => $fields['reference_code'], 'asserted_by' => $op['actor_id'], 'provenance' => 'Staff assertion; not independently verified'], JSON_THROW_ON_ERROR))]);
        foreach ($fields['answers'] as $code => $value) {
            DB::table('assessment_answers')->insert(['assessment_id' => $op['id'], 'question_code' => $code, 'value' => $value, 'na_reason_ciphertext' => isset($fields['na_reasons'][$code]) ? Crypt::encryptString($fields['na_reasons'][$code]) : null]);
        }
    }
}
