<?php

namespace App\Actions;

use App\Models\User;
use App\Support\Batches\Context;
use App\Support\Batches\Input;
use App\Support\Collection\AdminMutation;
use App\Support\Collection\Review;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AuthorizeBatchMedia
{
    public function token(User $actor, string $batch, string $medium): string
    {
        $b = $this->control($actor, $batch, $medium);
        $d = DB::table('batch_detachments')->where('batch_id', $batch)->where('subject_id', $medium)->firstOrFail();
        $snapshot = app(\App\Support\Sanitization\Context::class)->snapshot($actor, app(\App\Support\Assessments\Context::class)->authorized($actor, $medium));

        return AdminMutation::hash(['batch_id' => $batch, 'medium_id' => $medium, 'detachment' => (array) $d, 'snapshot' => $snapshot, 'access' => json_decode(DB::selectOne('SELECT batch_access(?,?) AS context', [$actor->id, $snapshot['facility_party_id']])->context, true), 'discrepancies' => DB::table('batch_records')->where('batch_id', $batch)->where('kind', 'discrepancy')->count()]);
    }

    public function record(User $actor, string $batch, string $medium, #[\SensitiveParameter] array $fields, ?UploadedFile $file = null): string
    {
        $stored = null;
        $files = app(DismantleBatches::class);
        try {
            return DB::transaction(function () use ($actor, $batch, $medium, $fields, $file, &$stored, $files) {
                Review::lock();
                $actor = app(\App\Support\Receipts\Context::class)->actor($actor);
                $b = $this->control($actor, $batch, $medium);
                $d = DB::table('batch_detachments')->where('batch_id', $batch)->where('subject_id', $medium)->firstOrFail();
                $fields = Input::validate($fields, 'authorize', $file);
                $action = 'media_authorize:'.$medium;
                $hash = AdminMutation::hash(['batch_id' => $batch, 'medium_id' => $medium] + $fields);
                if ($retry = AdminMutation::retry($actor, $action, $fields['idempotency_key'], $hash)) {
                    return $retry;
                }
                foreach (['subjects' => 'id', 'items' => 'subject_id', 'custody_current' => 'subject_id', 'assessment_current' => 'subject_id'] as $table => $key) {
                    DB::table($table)->whereIn($key, [$medium, $d->parent_id])->orderBy($key)->lockForUpdate()->get();
                }
                $cur = DB::table('media_authorization_current')->where('subject_id', $medium)->lockForUpdate()->first();
                abort_unless((int) ($cur?->version ?? 0) === (int) $fields['expected_version'] && hash_equals($this->token($actor, $batch, $medium), $fields['expected_context']), 409, 'Media, access or authorization changed. Reload the batch and open a fresh authorization form.');
                $row = app(\App\Support\Assessments\Context::class)->authorized($actor, $medium);
                $snap = app(\App\Support\Sanitization\Context::class)->snapshot($actor, $row);
                $parent = DB::table('subjects')->where('id', $d->parent_id)->firstOrFail();
                $observed = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $fields['observed_at'], 'UTC')->startOfMinute();
                $earliest = CarbonImmutable::parse(DB::table('batch_records')->where('id', $d->record_id)->value('observed_at'))->startOfMinute();
                if ($observed->isFuture() || $observed->lt($earliest)) {
                    throw ValidationException::withMessages(['observed_at' => 'Authorization time must be from actual detachment through now (UTC).']);
                }
                $id = Str::uuid()->toString();
                $command = AdminMutation::command($actor, $action, $fields['idempotency_key'], $hash, $id);
                $record = ['id' => $id, 'subject_id' => $medium, 'parent_id' => $d->parent_id, 'batch_id' => $batch, 'authorization_context_version' => $snap['authorization_context_version'], 'version' => ($cur?->version ?? 0) + 1, 'predecessor_id' => $cur?->record_id, 'inventory_version' => DB::table('media_inventory_current')->where('parent_id', $d->parent_id)->value('version'), 'parent_subject_version' => $parent->version, 'parent_custody_event_id' => DB::table('custody_current')->where('subject_id', $d->parent_id)->value('last_event_id'), 'parent_custody_version' => DB::table('custody_current')->where('subject_id', $d->parent_id)->value('version'), 'checks' => json_encode($fields['checks'], JSON_THROW_ON_ERROR), 'actor_id' => $actor->id, 'command_id' => $command, 'event_id' => Str::uuid()->toString(), 'observed_at' => $observed, 'recorded_at' => now(), 'reason_ciphertext' => Crypt::encryptString($fields['reason'])] + array_intersect_key($snap, array_flip(['source_subject_version', 'custody_event_id', 'custody_version', 'facility_party_id', 'facility_version', 'operator_version', 'assessment_id', 'assessment_version', 'decision_id', 'membership_id', 'membership_version', 'actor_session_version'])) + $files->evidence($actor, $fields, $file, $stored);
                if (Schema::hasColumn('media_authorizations', 'downstream_receipt_id')) {
                    $record['downstream_receipt_id'] = $b->downstream_receipt_id ?? null;
                }
                $candidate = DB::selectOne('SELECT media_authorization_applicable(jsonb_populate_record(NULL::media_authorizations,?::jsonb)) AS eligible', [json_encode($record, JSON_THROW_ON_ERROR)])->eligible;
                if (! $candidate) {
                    throw ValidationException::withMessages(['submission' => 'Requires coherent batch/detachment control, retained RECYCLE decision, exact HDD/SSD facility capability and no safety/identity/unexpected-media restriction. Resolve setup through supported controls; no override is available.']);
                }
                DB::table('domain_events')->insert(['id' => $record['event_id'], 'subject_id' => $medium, 'aggregate_kind' => 'media_authorization', 'aggregate_id' => $medium, 'aggregate_version' => $record['version'], 'actor_id' => $actor->id, 'command_id' => $command, 'type' => 'media_authorized', 'occurred_at' => $observed, 'recorded_at' => $record['recorded_at'], 'safe_payload' => json_encode(['record_id' => $id, 'milestone' => 'Batch-controlled treatment authorized; applicability review and separate assurance remain required.'])]);
                DB::table('media_authorizations')->insert($record);
                $projection = ['version' => $record['version'], 'record_id' => $id];
                $cur ? DB::table('media_authorization_current')->where('subject_id', $medium)->update($projection) : DB::table('media_authorization_current')->insert(['subject_id' => $medium] + $projection);

                return $id;
            });
        } catch (Throwable $e) {
            $files->cleanup($stored);
            throw $e;
        }
    }

    private function control(User $actor, string $batch, string $medium): object
    {
        $party = app(Context::class)->party($actor);
        $b = DB::table('dismantling_batches')->where('subject_id', $batch)->firstOrFail();
        if ($b->facility_party_id === $party) {
            return app(Context::class)->batch($actor, $batch);
        }
        $receipt = DB::table('downstream_records as r')->join('downstream_shipments as h', 'h.id', '=', 'r.shipment_id')->join('output_profile_records as p', 'p.id', '=', 'h.profile_record_id')->where('r.batch_id', $batch)->where('r.output_id', $medium)->where('r.kind', 'accept')->where('r.canonical', true)->where('h.receiver_actor_id', $actor->id)->where('p.facility_party_id', $party)->first(['r.id']);
        abort_unless($receipt, 404);
        $b->downstream_receipt_id = $receipt->id;

        return $b;
    }
}
