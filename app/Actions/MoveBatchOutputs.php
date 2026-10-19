<?php

namespace App\Actions;

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

class MoveBatchOutputs
{
    public function record(User $actor, string $batch, string $kind, #[\SensitiveParameter] array $fields, ?UploadedFile $file = null): string
    {
        abort_unless(isset(Context::LABELS[$kind]), 404);
        $stored = null;
        try {
            return DB::transaction(function () use ($actor, $batch, $kind, $fields, $file, &$stored) {
                Review::lock();
                $actor = app(\App\Support\Receipts\Context::class)->actor($actor);
                $fields = Input::validate($fields, $kind, $file);
                $ctx = app(Context::class);
                $h = isset($fields['shipment_id']) ? $ctx->shipment($actor, $fields['shipment_id']) : null;
                if (! $h) {
                    $ctx->source($actor, $batch);
                } else {
                    abort_unless($h->batch_id === $batch, 404);
                }
                $action = 'downstream_'.$kind.':'.$batch;
                $hash = AdminMutation::hash(['batch_id' => $batch] + $fields);
                if ($retry = AdminMutation::retry($actor, $action, $fields['idempotency_key'], $hash)) {
                    return $retry;
                }
                DB::table('dismantling_batches')->where('subject_id', $batch)->lockForUpdate()->firstOrFail();
                abort_unless($ctx->version($batch) === (int) $fields['expected_version'] && hash_equals($ctx->token($batch, $actor, $h?->id), $fields['expected_context']), 409, 'Stock, review, scope or shipment changed. Reload Downstream movements and use a fresh form.');
                $id = Str::uuid()->toString();
                $output = $kind === 'measurement_dispute' ? null : ($h?->output_id ?? ($fields['output_id'] ?? null));
                $o = $output ? DB::table('batch_outputs')->where('batch_id', $batch)->where('subject_id', $output)->lockForUpdate()->firstOrFail() : null;
                $canonical = $o?->type === 'storage';
                if ($output) {
                    foreach (['subjects' => 'id', 'custody_current' => 'subject_id', 'sanitization_current' => 'subject_id'] as $table => $key) {
                        DB::table($table)->where($key, $output)->lockForUpdate()->get();
                    }
                }
                if ($kind === 'draft') {
                    $pr = DB::table('output_profile_records')->where('id', $fields['profile_record_id'])->firstOrFail();
                    $membership = DB::table('memberships')->where('user_id', $fields['receiver_actor_id'])->firstOrFail();
                    $custody = $canonical ? DB::table('custody_current')->where('subject_id', $output)->firstOrFail() : null;
                    $h = (object) ['id' => Str::uuid()->toString(), 'batch_id' => $batch, 'output_id' => $output, 'profile_record_id' => $pr->id, 'receiver_actor_id' => $fields['receiver_actor_id'], 'receiver_membership_id' => $membership->id, 'receiver_scope_version' => $membership->version, 'sender_actor_id' => $actor->id, 'requested_kg' => $fields['kg'], 'hazards' => $fields['hazards'], 'source_subject_version' => $canonical ? DB::table('subjects')->where('id', $output)->value('version') : null, 'source_custody_version' => $custody?->version, 'source_event_id' => $custody?->last_event_id, 'created_record_id' => $id];
                    DB::table('downstream_shipments')->insert((array) $h);
                }
                $previous = DB::table('downstream_batches')->where('batch_id', $batch)->first();
                $observed = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $fields['observed_at'], 'UTC')->startOfMinute();
                $command = AdminMutation::command($actor, $action, $fields['idempotency_key'], $hash, $id);
                $record = ['id' => $id, 'batch_id' => $batch, 'version' => ($previous?->version ?? 0) + 1, 'predecessor_id' => $previous?->record_id, 'kind' => $kind, 'shipment_id' => $h?->id, 'output_id' => $output, 'related_record_id' => $fields['related_record_id'] ?? null, 'kg' => in_array($kind, ['draft', 'close', 'measurement_dispute'], true) ? null : $fields['kg'], 'canonical' => $canonical, 'identity_code' => $fields['identity_code'] ?? null, 'condition' => $fields['condition'] ?? null, 'outcome' => $fields['outcome'] ?? null, 'assurance_record_id' => $kind === 'dispatch' && $canonical ? DB::table('sanitization_current')->where('subject_id', $output)->value('record_id') : null, 'context' => json_encode($ctx->snapshot($batch, $actor, $h?->id), JSON_THROW_ON_ERROR), 'attested' => true, 'actor_id' => $actor->id, 'assignee_id' => $h?->receiver_actor_id ?? $actor->id, 'command_id' => $command, 'event_id' => Str::uuid()->toString(), 'physical_event_id' => $kind === 'accept' && $canonical ? Str::uuid()->toString() : null, 'observed_at' => $observed, 'recorded_at' => now(), 'reason_ciphertext' => Crypt::encryptString($fields['reason']), 'issuer_ciphertext' => isset($fields['issuer']) ? Crypt::encryptString($fields['issuer']) : null] + app(DismantleBatches::class)->evidence($actor, $fields, $file, $stored);
                DB::table('domain_events')->insert(['id' => $record['event_id'], 'subject_id' => null, 'aggregate_kind' => 'downstream', 'aggregate_id' => $batch, 'aggregate_version' => $record['version'], 'actor_id' => $actor->id, 'command_id' => $command, 'type' => 'downstream_'.$kind, 'occurred_at' => $observed, 'recorded_at' => now(), 'safe_payload' => json_encode(['record_id' => $id])]);
                if ($record['physical_event_id']) {
                    $party = DB::table('output_profile_records')->where('id', $h->profile_record_id)->value('facility_party_id');
                    DB::table('domain_events')->insert(['id' => $record['physical_event_id'], 'subject_id' => $output, 'aggregate_kind' => 'item', 'aggregate_id' => $output, 'aggregate_version' => $h->source_subject_version + 1, 'actor_id' => $actor->id, 'command_id' => $command, 'type' => 'downstream_accepted', 'occurred_at' => $observed, 'recorded_at' => now(), 'safe_payload' => json_encode(['record_id' => $id, 'milestone' => 'Canonical medium accepted by downstream facility; ownership unchanged.'])]);
                }
                DB::table('downstream_records')->insert($record);
                if ($record['physical_event_id']) {
                    DB::table('subjects')->where('id', $output)->update(['version' => $h->source_subject_version + 1]);
                    DB::table('custody_current')->where('subject_id', $output)->update(['party_id' => $party, 'version' => $h->source_custody_version + 1, 'last_event_id' => $record['physical_event_id']]);
                }
                $projection = ['version' => $record['version'], 'record_id' => $id, 'closure_record_id' => $kind === 'close' ? $id : $previous?->closure_record_id];
                $previous ? DB::table('downstream_batches')->where('batch_id', $batch)->update($projection) : DB::table('downstream_batches')->insert(['batch_id' => $batch] + $projection);

                return $id;
            });
        } catch (Throwable $e) {
            app(DismantleBatches::class)->cleanup($stored);
            Violation::raise($e);
        }
    }
}
