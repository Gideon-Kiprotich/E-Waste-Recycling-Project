<?php

namespace App\Actions;

use App\Models\User;
use App\Support\Batches\Context;
use App\Support\Batches\Input;
use App\Support\Batches\Mass;
use App\Support\Collection\AdminMutation;
use App\Support\Collection\PrivateEvidence;
use App\Support\Collection\Review;
use App\Support\Downstream\Violation;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class DismantleBatches
{
    public function record(User $actor, string $batch, string $kind, #[\SensitiveParameter] array $fields, ?UploadedFile $file = null): string
    {
        abort_unless(Str::isUuid($batch) && isset(Context::LABELS[$kind]), 404);
        $stored = null;
        try {
            return DB::transaction(function () use ($actor, $batch, $kind, $fields, $file, &$stored) {
                Review::lock();
                $actor = app(\App\Support\Receipts\Context::class)->actor($actor);
                $ctx = app(Context::class);
                $party = $ctx->party($actor);
                $fields = Input::validate($fields, $kind, $file);
                $action = 'batch_'.$kind.':'.$batch;
                $hash = AdminMutation::hash(['batch_id' => $batch] + $fields);
                $b = DB::table('dismantling_batches')->where('subject_id', $batch)->lockForUpdate()->first();
                if ($b) {
                    $ctx->batch($actor, $batch);
                }
                if ($retry = AdminMutation::retry($actor, $action, $fields['idempotency_key'], $hash)) {
                    return $retry;
                }
                abort_unless((int) ($b?->version ?? 0) === (int) $fields['expected_version'] && hash_equals($ctx->token($actor, $batch), $fields['expected_context']), 409, 'Batch, inventory, custody, access or measurements changed. Reload Dismantling / Batches and use a fresh form.');
                if (! $b) {
                    abort_unless($kind === 'draft', 409, 'Save a draft selection first; it grants no authority to dismantle.');
                    DB::table('subjects')->insert(['id' => $batch, 'kind' => 'batch', 'public_code' => 'EWC-'.strtoupper(bin2hex(random_bytes(16))), 'version' => 1, 'created_at' => now()]);
                    DB::table('dismantling_batches')->insert(['subject_id' => $batch, 'facility_party_id' => $party, 'created_by' => $actor->id, 'version' => 0, 'state' => 'draft', 'inputs' => '{'.implode(',', $fields['inputs']).'}']);
                    $b = DB::table('dismantling_batches')->where('subject_id', $batch)->firstOrFail();
                }
                $inputs = $kind === 'draft' ? $fields['inputs'] : $ctx->inputs($b);
                foreach ($inputs as $s) {
                    app(\App\Support\Assessments\Context::class)->authorized($actor, $s);
                }
                $ids = DB::table('media_inventory_entries')->whereIn('parent_id', $inputs)->whereNotNull('medium_id')->pluck('medium_id')->merge($inputs)->unique()->sort()->values()->all();
                foreach (['subjects' => 'id', 'items' => 'subject_id', 'custody_current' => 'subject_id', 'assessment_current' => 'subject_id', 'media_inventory_current' => 'parent_id', 'sanitization_current' => 'subject_id'] as $table => $key) {
                    DB::table($table)->whereIn($key, $ids)->orderBy($key)->lockForUpdate()->get();
                }
                $this->step($actor, $b, $kind, $fields, $inputs);
                $measurement = in_array($kind, ['commit', 'output', 'measurement'], true) ? Mass::measurement($fields) : ['measure_mode' => null, 'net_kg' => null, 'gross_kg' => null, 'tare_kg' => null];
                $observed = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $fields['observed_at'], 'UTC')->startOfMinute();
                $earliest = DB::table('batch_records')->where('id', $b->record_id)->value('observed_at') ?? DB::table('domain_events as e')->join('custody_current as c', 'c.last_event_id', '=', 'e.id')->whereIn('c.subject_id', $inputs)->max('e.occurred_at');
                if ($observed->isFuture() || $observed->lt(CarbonImmutable::parse($earliest)->startOfMinute())) {
                    throw ValidationException::withMessages(['observed_at' => 'Use actual UTC observation time from the latest control/batch activity through now; explain retrospective entry privately.']);
                }
                $id = Str::uuid()->toString();
                $command = AdminMutation::command($actor, $action, $fields['idempotency_key'], $hash, $id);
                $target = match ($kind) {
                    'commit' => $batch, 'output' => Str::uuid()->toString(), 'measurement' => $fields['target_id'], default => null,
                };
                if ($kind === 'output') {
                    DB::table('subjects')->insert(['id' => $target, 'kind' => 'output', 'public_code' => 'EWC-'.strtoupper(bin2hex(random_bytes(16))), 'version' => 1, 'created_at' => now()]);
                }
                $snapshot = $ctx->snapshot($actor, $b, $inputs);
                $record = ['id' => $id, 'batch_id' => $batch, 'version' => $b->version + 1, 'predecessor_id' => $b->record_id, 'kind' => $kind, 'inputs' => '{'.implode(',', $inputs).'}', 'target_id' => $target, 'previous_measurement_id' => $fields['previous_measurement_id'] ?? null, 'output_type' => $fields['output_type'] ?? null, 'material_code' => $fields['material_code'] ?? null, 'source_item_id' => $fields['source_item_id'] ?? null, 'unit' => 'kg', 'measurement_source_ciphertext' => $measurement['measure_mode'] ? Crypt::encryptString($fields['measurement_source']) : null, 'checks' => json_encode((object) $fields['checks'], JSON_THROW_ON_ERROR), 'detached' => '{'.implode(',', $fields['detached']).'}', 'context' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'actor_id' => $actor->id, 'command_id' => $command, 'event_id' => Str::uuid()->toString(), 'observed_at' => $observed, 'recorded_at' => now(), 'reason_ciphertext' => Crypt::encryptString($fields['reason'])] + $measurement + $this->evidence($actor, $fields, $file, $stored);
                DB::table('domain_events')->insert($this->event($record, $batch, 'batch', $record['version'], 'batch_'.$kind, $record['event_id'], Context::LABELS[$kind].' recorded; no downstream delivery or completed recycling.'));
                DB::table('batch_records')->insert($record);
                $projection = ['version' => $record['version'], 'record_id' => $id, 'inputs' => $record['inputs']];
                if ($kind === 'commit') {
                    $this->commit($record, $snapshot);
                    $projection += ['state' => 'processing', 'commit_record_id' => $id, 'input_measurement_id' => $id];
                } elseif ($kind === 'output') {
                    DB::table('batch_outputs')->insert(['subject_id' => $target, 'batch_id' => $batch, 'type' => $fields['output_type'], 'material_code' => $fields['material_code'], 'source_item_id' => $fields['source_item_id'] ?? null, 'production_record_id' => $id, 'measurement_record_id' => $measurement['measure_mode'] ? $id : null]);
                } elseif ($kind === 'measurement') {
                    if ($target === $batch) {
                        $projection['input_measurement_id'] = $id;
                    } else {
                        DB::table('batch_outputs')->where('subject_id', $target)->where('batch_id', $batch)->update(['measurement_record_id' => $id]);
                    }
                }
                DB::table('dismantling_batches')->where('subject_id', $batch)->update($projection);

                return $id;
            });
        } catch (Throwable $e) {
            $this->cleanup($stored);
            Violation::raise($e);
        }
    }

    private function step(User $actor, object $b, string $kind, array $fields, array $inputs): void
    {
        if ($kind === 'draft') {
            abort_unless($b->state === 'draft', 409, 'Committed membership cannot be edited.');

            return;
        }
        if ($kind === 'commit') {
            abort_unless($b->state === 'draft', 409, 'Actual dismantling is already recorded.');
            foreach ($inputs as $s) {
                if ($messages = app(Context::class)->blockers($actor, $s)) {
                    throw ValidationException::withMessages(['submission' => implode(' ', $messages)]);
                }
            }
            $expected = DB::table('media_inventory_entries')->whereIn('parent_id', $inputs)->whereNotNull('medium_id')->orderBy('medium_id')->pluck('medium_id')->all();
            if ($expected !== $fields['detached']) {
                throw ValidationException::withMessages(['detached' => 'Explicitly confirm actual detachment of every displayed canonical medium. Missing or additional media cannot be hidden.']);
            }

            return;
        }
        abort_unless($b->state === 'processing', 409, 'Record actual dismantling before output accounting.');
        if ($kind !== 'discrepancy' && ! DB::selectOne('SELECT batch_control_ready(?,?) AS ready', [$b->subject_id, $actor->id])->ready) {
            throw ValidationException::withMessages(['submission' => 'Current exact input and HDD/SSD facility capabilities are required. Ask an Administrator to review Operators and places; restore legitimate eligibility and reload.']);
        }
        if ($kind !== 'discrepancy' && DB::table('batch_records')->where('batch_id', $b->subject_id)->where('kind', 'discrepancy')->exists()) {
            throw ValidationException::withMessages(['submission' => 'Unexpected media blocks ordinary accounting and new treatment. Reviewed provenance/identity resolution is unavailable; do not invent an owner or change membership.']);
        }
        if ($kind === 'measurement') {
            $old = $fields['target_id'] === $b->subject_id ? $b->input_measurement_id : DB::table('batch_outputs')->where('batch_id', $b->subject_id)->where('subject_id', $fields['target_id'])->firstOrFail()->measurement_record_id;
            abort_unless($old === ($fields['previous_measurement_id'] ?? null), 409, 'The measurement changed. Reload its current original value.');
        }
        if ($kind === 'output' && isset($fields['source_item_id']) && ! in_array($fields['source_item_id'], $inputs, true)) {
            throw ValidationException::withMessages(['source_item_id' => 'Use an evidenced source member of this batch or leave aggregate source unknown.']);
        }
        if ($kind === 'accounted' && (! DB::table('batch_outputs')->where('batch_id', $b->subject_id)->exists() || DB::table('batch_outputs')->where('batch_id', $b->subject_id)->whereNull('measurement_record_id')->exists())) {
            throw ValidationException::withMessages(['submission' => 'Record genuine outputs and complete every pending output measurement before attesting complete accounting.']);
        }
    }

    private function commit(array $r, array $snapshot): void
    {
        foreach ($snapshot['inputs'] as $x) {
            $event = Str::uuid()->toString();
            DB::table('domain_events')->insert($this->event($r, $x['id'], 'item', $x['subject_version'] + 1, 'dismantled_in_batch', $event, 'Actual dismantling recorded; source permanently linked to batch.'));
            DB::table('batch_members')->insert(['subject_id' => $x['id'], 'batch_id' => $r['batch_id'], 'record_id' => $r['id'], 'decision_id' => $x['decision_id'], 'inventory_record_id' => $x['inventory_record'], 'source_subject_version' => $x['subject_version'], 'source_custody_event_id' => $x['custody_event'], 'source_custody_version' => $x['custody_version'], 'facility_party_id' => $x['party'], 'event_id' => $event]);
            DB::table('items')->where('subject_id', $x['id'])->update(['processing_state' => 'dismantled']);
            DB::table('subjects')->where('id', $x['id'])->update(['version' => $x['subject_version'] + 1]);
            foreach ($x['media'] as $m) {
                if (! $m['id']) {
                    continue;
                }
                $event = Str::uuid()->toString();
                DB::table('domain_events')->insert($this->event($r, $m['id'], 'item', $m['subject_version'] + 1, 'media_detached_in_batch', $event, 'Canonical medium detached into controlled batch; facility custody unchanged.'));
                DB::table('batch_detachments')->insert(['subject_id' => $m['id'], 'batch_id' => $r['batch_id'], 'parent_id' => $x['id'], 'placement_id' => $m['entry_id'], 'placement_record_id' => $m['placement_record'], 'record_id' => $r['id'], 'source_subject_version' => $m['subject_version'], 'source_custody_event_id' => $m['custody_event'], 'source_custody_version' => $m['custody_version'], 'facility_party_id' => $m['party'], 'event_id' => $event]);
                DB::table('batch_outputs')->insert(['subject_id' => $m['id'], 'batch_id' => $r['batch_id'], 'type' => 'storage', 'material_code' => 'canonical_storage', 'source_item_id' => $x['id'], 'production_record_id' => $r['id']]);
                DB::table('subjects')->where('id', $m['id'])->update(['version' => $m['subject_version'] + 1]);
            }
        }
    }

    private function event(array $r, string $subject, string $aggregate, int $version, string $type, string $event, string $text): array
    {
        return ['id' => $event, 'subject_id' => $subject, 'aggregate_kind' => $aggregate, 'aggregate_id' => $subject, 'aggregate_version' => $version, 'actor_id' => $r['actor_id'], 'command_id' => $r['command_id'], 'type' => $type, 'occurred_at' => $r['observed_at'], 'recorded_at' => $r['recorded_at'], 'safe_payload' => json_encode(['record_id' => $r['id'], 'milestone' => $text])];
    }

    public function evidence(User $actor, array $fields, ?UploadedFile $file, ?array &$stored): array
    {
        if ($file) {
            $stored = app(PrivateEvidence::class)->store($file);
            $id = Str::uuid()->toString();
            DB::table('attachments')->insert($stored + ['id' => $id, 'uploaded_by' => $actor->id, 'privacy_class' => 'private']);

            return ['attachment_id' => $id, 'reference_ciphertext' => null];
        }

        return ['attachment_id' => null, 'reference_ciphertext' => Crypt::encryptString(json_encode(['issuer' => $fields['reference_issuer'], 'code' => $fields['reference_code'], 'provenance' => 'Accountable assertion; not independent certification'], JSON_THROW_ON_ERROR))];
    }

    public function cleanup(?array $stored): void
    {
        if ($stored && ! DB::table('attachments')->where('storage_key', $stored['storage_key'])->exists()) {
            Storage::disk('directory_evidence')->delete($stored['storage_key']);
        }
    }
}
