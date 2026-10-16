<?php

namespace App\Actions;

use App\Models\User;
use App\Support\Collection\AdminMutation;
use App\Support\Collection\PrivateEvidence;
use App\Support\Collection\Review;
use App\Support\Devices\Identifiers;
use App\Support\MediaInventory\Context;
use App\Support\MediaInventory\Input;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class InventoryMedia
{
    public function record(User $actor, string $parent, string $kind, #[\SensitiveParameter] array $fields, ?UploadedFile $file = null): string
    {
        abort_unless(in_array($kind, ['identify', 'link', 'unresolved', 'correct', 'attest', 'authorize'], true), 404);
        $stored = null;
        try {
            return DB::transaction(function () use ($actor, $parent, $kind, $fields, $file, &$stored) {
                Review::lock();
                $actor = app(\App\Support\Receipts\Context::class)->actor($actor);
                $ctx = app(Context::class);
                $row = $ctx->parent($actor, $parent);
                $fields = Input::validate($fields, $kind, $file);
                $action = $kind === 'authorize' ? 'media_authorize:'.$fields['medium_id'] : 'media_inventory_'.$kind.':'.$parent;
                $hash = AdminMutation::hash(['parent_id' => $parent] + $fields);
                if ($retry = AdminMutation::retry($actor, $action, $fields['idempotency_key'], $hash)) {
                    return $retry;
                }
                DB::table('subjects')->where('id', $parent)->lockForUpdate()->first();
                DB::table('custody_current')->where('subject_id', $parent)->lockForUpdate()->first();
                DB::table('media_inventory_current')->where('parent_id', $parent)->lockForUpdate()->first();
                $row = $ctx->parent($actor, $parent);
                abort_unless($ctx->version($parent) === (int) $fields['expected_version'] && hash_equals($ctx->token($actor, $row), $fields['expected_context']), 409, 'Inventory, inspection, custody or access changed. Reload Media inventory and open a fresh form.');
                if (! DB::selectOne('SELECT media_parent_ready(?,?) AS ready', [$parent, $actor->id])->ready) {
                    throw ValidationException::withMessages(['submission' => 'Accepted compatible facility custody and no unresolved safety/identity restriction are required. Review Custody handovers and Assessments / Inspection.']);
                }
                $this->checks($fields, $kind);
                $observed = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $fields['observed_at'], 'UTC')->startOfMinute();
                $earliest = DB::table('media_inventory_records')->where('parent_id', $parent)->orderByDesc('version')->value('observed_at') ?? DB::table('domain_events')->where('id', $row->last_event_id)->value('occurred_at');
                if ($observed->isFuture() || $observed->lt(CarbonImmutable::parse($earliest)->startOfMinute())) {
                    throw ValidationException::withMessages(['observed_at' => 'Enter actual UTC observation time from the latest custody/inventory observation through now. Explain retrospective entry.']);
                }
                $id = Str::uuid()->toString();
                $command = AdminMutation::command($actor, $action, $fields['idempotency_key'], $hash, $id);
                $event = Str::uuid()->toString();
                $base = $this->base($actor, $row, $ctx, $fields, $observed, $id, $command, $event);
                $base += $this->evidence($actor, $fields, $file, $stored);
                if ($kind === 'authorize') {
                    $this->authorize($actor, $row, $base, $fields);
                } else {
                    $this->observe($actor, $row, $base, $fields, $kind);
                }

                return $id;
            });
        } catch (Throwable $e) {
            if ($stored && ! DB::table('attachments')->where('storage_key', $stored['storage_key'])->exists()) {
                Storage::disk('directory_evidence')->delete($stored['storage_key']);
            }
            if ($e instanceof QueryException && Identifiers::isDuplicateConflict($e)) {
                throw ValidationException::withMessages(['identifier' => Identifiers::DUPLICATE_MESSAGE]);
            }
            throw $e;
        }
    }

    private function base(User $actor, object $row, Context $ctx, array $fields, CarbonImmutable $observed, string $id, string $command, string $event): array
    {
        $snapshot = $ctx->snapshot($actor, $row);
        unset($snapshot['authorization_context_version'], $snapshot['authorization_id']);

        return ['id' => $id, 'parent_id' => $row->subject_id, 'actor_id' => $actor->id, 'command_id' => $command, 'event_id' => $event, 'observed_at' => $observed, 'recorded_at' => now(), 'reason_ciphertext' => Crypt::encryptString($fields['reason']), 'checks' => json_encode((object) $fields['checks'], JSON_THROW_ON_ERROR)] + $snapshot;
    }

    private function checks(array $fields, string $kind): void
    {
        if (($kind !== 'attest' || $fields['completeness'] === 'YES') && array_filter($fields['checks'], fn ($a) => $a !== 'YES')) {
            throw ValidationException::withMessages(['checks' => 'This action requires every applicable check YES. Record an unresolved observation or incomplete inventory honestly.']);
        }

    }

    private function observe(User $actor, object $row, array $base, array $fields, string $kind): void
    {
        $ctx = app(Context::class);
        $entry = null;
        if (! empty($fields['entry_id'])) {
            $entry = DB::table('media_inventory_entries')->where('parent_id', $row->subject_id)->where('id', $fields['entry_id'])->lockForUpdate()->first();
            abort_unless($entry, 404);
            if ($kind !== 'correct' && $entry->state !== 'unresolved') {
                throw ValidationException::withMessages(['entry_id' => 'This entry already has a retained identity. Use Correct inventory entry to record a dispute.']);
            }
        }
        $entryId = $entry?->id ?? Str::uuid()->toString();
        $medium = null;
        $originEvent = null;
        $state = 'unresolved';
        if ($kind === 'identify') {
            [$medium, $originEvent] = $this->identify($actor, $row, $base, $fields);
            $state = 'confirmed';
        } elseif ($kind === 'link') {
            $candidate = $ctx->candidates($actor, $row)->firstWhere('public_code', $fields['existing_code']);
            if (! $candidate || app(\App\Support\Assessments\Context::class)->blockers($candidate) || app(\App\Support\Assessments\Context::class)->restricted($candidate->subject_id)) {
                throw ValidationException::withMessages(['existing_code' => 'No eligible matching medium is available in your authorized facility and owner scope. Retain uncertainty; do not create a replacement identity.']);
            }
            $medium = $candidate->subject_id;
            DB::table('subjects')->where('id', $medium)->lockForUpdate()->first();
            $state = 'confirmed';
        } elseif ($kind === 'correct') {
            $medium = $entry->medium_id;
            $state = $fields['entry_state'];
            if (($medium && $state !== 'disputed') || (! $medium && ! in_array($state, ['unresolved', 'not_present'], true))) {
                throw ValidationException::withMessages(['entry_state' => 'A bound medium can only be marked disputed; its placement and obligations remain. Unbound observations may be corrected as unresolved or not present.']);
            }
        }
        if ($kind === 'attest' && $fields['completeness'] === 'YES' && $ctx->entries($row->subject_id)->whereIn('state', ['unresolved', 'disputed'])->isNotEmpty()) {
            throw ValidationException::withMessages(['completeness' => 'Unresolved or disputed entries block completeness. No media were removed.']);
        }
        if ($kind === 'attest' && $fields['completeness'] === 'YES' && DB::table('assessment_current as ac')->join('assessments as a', 'a.id', '=', 'ac.assessment_id')->where('ac.subject_id', $row->subject_id)->value('a.data_status') === 'NO' && ! filled($fields['na_justification'] ?? null)) {
            throw ValidationException::withMessages(['na_justification' => 'Explicitly justify why complete evidenced inventory establishes no applicable nonvolatile storage. An empty list or consumer NO is insufficient.']);
        }
        $current = DB::table('media_inventory_current')->where('parent_id', $row->subject_id)->first();
        $record = $base + ['version' => ($current?->version ?? 0) + 1, 'predecessor_id' => $current?->record_id, 'kind' => $kind, 'na_reason_ciphertext' => filled($fields['na_justification'] ?? null) ? Crypt::encryptString($fields['na_justification']) : null, 'entry_id' => $kind === 'attest' ? null : $entryId, 'medium_id' => $medium, 'entry_state' => $kind === 'attest' ? null : $state, 'completeness' => $fields['completeness'] ?? null, 'placement_ciphertext' => isset($fields['placement']) ? Crypt::encryptString($fields['placement']) : null];
        $this->event($actor, $record, 'media_inventory', $row->subject_id, 'media_inventory_'.$kind);
        DB::table('media_inventory_records')->insert($record);
        if ($kind !== 'attest') {
            $projection = ['medium_id' => $medium, 'state' => $state, 'record_id' => $base['id']];
            if ($entry) {
                DB::table('media_inventory_entries')->where('id', $entryId)->update($projection);
            } else {
                DB::table('media_inventory_entries')->insert(['id' => $entryId, 'parent_id' => $row->subject_id, 'created_record_id' => $base['id']] + $projection);
            }
        }
        if ($originEvent) {
            DB::table('media_origins')->insert(['subject_id' => $medium, 'parent_id' => $row->subject_id, 'entry_id' => $entryId, 'record_id' => $base['id'], 'event_id' => $originEvent]);
        }
        $projection = ['version' => $record['version'], 'record_id' => $base['id']];
        if ($current) {
            DB::table('media_inventory_current')->where('parent_id', $row->subject_id)->update($projection);
        } else {
            DB::table('media_inventory_current')->insert(['parent_id' => $row->subject_id] + $projection);
        }
    }

    private function identify(User $actor, object $row, array $base, array $fields): array
    {
        $normalized = Identifiers::normalize($fields['identifier'] ?? null);
        $digest = $normalized === null ? null : Identifiers::digest($normalized);
        if ($digest) {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$digest]);
            if (DB::table('items')->where('identifier_hmac', $digest)->exists()) {
                throw ValidationException::withMessages(['identifier' => Identifiers::DUPLICATE_MESSAGE]);
            }
        }
        $medium = Str::uuid()->toString();
        $event = Str::uuid()->toString();
        DB::table('subjects')->insert(['id' => $medium, 'kind' => 'item', 'public_code' => 'EWC-'.strtoupper(bin2hex(random_bytes(16))), 'version' => 1, 'created_at' => now()]);
        DB::table('items')->insert(['subject_id' => $medium, 'owner_user_id' => $row->owner_user_id, 'category_id' => DB::table('categories')->where('code', 'processor_media')->value('id'), 'subtype' => $fields['media_kind'], 'model' => $fields['model'] ?? null, 'intake_class' => 'processor_derived', 'processing_state' => 'inventoried', 'data_status' => 'YES', 'identifier_kind' => $digest ? 'serial' : null, 'identifier_ciphertext' => $digest ? Crypt::encryptString(trim($fields['identifier'])) : null, 'identifier_hmac' => $digest, 'identifier_key_version' => $digest ? Identifiers::KEY_VERSION : null]);
        DB::table('storage_assets')->insert(['subject_id' => $medium, 'media_kind' => $fields['media_kind']]);
        DB::table('domain_events')->insert(['id' => $event, 'subject_id' => $medium, 'aggregate_kind' => 'item', 'aggregate_id' => $medium, 'aggregate_version' => 1, 'actor_id' => $actor->id, 'command_id' => $base['command_id'], 'type' => 'media_identified', 'occurred_at' => $base['observed_at'], 'recorded_at' => $base['recorded_at'], 'safe_payload' => json_encode(['milestone' => 'Medium identified at facility; no first receipt or removal asserted.'])]);
        DB::table('custody_current')->insert(['subject_id' => $medium, 'party_id' => $row->party_id, 'last_event_id' => $event, 'version' => 1]);

        return [$medium, $event];
    }

    private function authorize(User $actor, object $row, array $base, array $fields): void
    {
        $entry = DB::table('media_inventory_entries')->where('parent_id', $row->subject_id)->where('medium_id', $fields['medium_id'])->where('state', 'confirmed')->first();
        abort_unless($entry, 404);
        DB::table('subjects')->where('id', $entry->medium_id)->lockForUpdate()->first();
        $current = DB::table('media_authorization_current')->where('subject_id', $entry->medium_id)->lockForUpdate()->first();
        abort_unless((int) ($current?->version ?? 0) === (int) $fields['expected_authorization_version'], 409, 'Media authorization changed. Reload current inventory.');
        $cc = DB::table('custody_current')->where('subject_id', $entry->medium_id)->lockForUpdate()->first();
        $record = array_merge($base, ['subject_id' => $entry->medium_id, 'version' => ($current?->version ?? 0) + 1, 'predecessor_id' => $current?->record_id, 'inventory_version' => app(Context::class)->version($row->subject_id), 'parent_subject_version' => $base['source_subject_version'], 'source_subject_version' => DB::table('subjects')->where('id', $entry->medium_id)->value('version'), 'parent_custody_event_id' => $base['custody_event_id'], 'parent_custody_version' => $base['custody_version'], 'custody_event_id' => $cc->last_event_id, 'custody_version' => $cc->version]);
        if (! DB::selectOne('SELECT media_authorization_applicable(jsonb_populate_record(NULL::media_authorizations,?::jsonb)) AS ready', [json_encode($record, JSON_THROW_ON_ERROR)])->ready) {
            throw ValidationException::withMessages(['medium_id' => 'Current non-HOLD parent decision, confirmed derived HDD/SSD, coherent control and explicit standalone HDD/SSD facility capability are required. Other media is inventory-only; standalone drives retain their own inspection.']);
        }
        $this->event($actor, $record, 'media_authorization', $entry->medium_id, 'media_authorized');
        DB::table('media_authorizations')->insert($record);
        $projection = ['version' => $record['version'], 'record_id' => $record['id']];
        if ($current) {
            DB::table('media_authorization_current')->where('subject_id', $entry->medium_id)->update($projection);
        } else {
            DB::table('media_authorization_current')->insert(['subject_id' => $entry->medium_id] + $projection);
        }
    }

    private function event(User $actor, array $r, string $kind, string $aggregate, string $type): void
    {
        DB::table('domain_events')->insert(['id' => $r['event_id'], 'subject_id' => $aggregate, 'aggregate_kind' => $kind, 'aggregate_id' => $aggregate, 'aggregate_version' => $r['version'], 'actor_id' => $actor->id, 'command_id' => $r['command_id'], 'type' => $type, 'occurred_at' => $r['observed_at'], 'recorded_at' => $r['recorded_at'], 'safe_payload' => json_encode(['record_id' => $r['id'], 'milestone' => 'Media inventory/authorization recorded; no treatment, removal or release.'])]);
    }

    private function evidence(User $actor, array $fields, ?UploadedFile $file, ?array &$stored): array
    {
        if ($file) {
            $stored = app(PrivateEvidence::class)->store($file);
            $id = Str::uuid()->toString();
            DB::table('attachments')->insert($stored + ['id' => $id, 'uploaded_by' => $actor->id, 'privacy_class' => 'private']);

            return ['attachment_id' => $id, 'reference_ciphertext' => null];
        }

        return ['attachment_id' => null, 'reference_ciphertext' => Crypt::encryptString(json_encode(['issuer' => $fields['reference_issuer'], 'code' => $fields['reference_code'], 'provenance' => 'Accountable staff assertion; not independently verified'], JSON_THROW_ON_ERROR))];
    }
}
