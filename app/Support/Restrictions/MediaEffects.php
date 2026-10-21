<?php

namespace App\Support\Restrictions;

use App\Models\User;
use App\Support\Batches\Mass;
use App\Support\Collection\AdminMutation;
use App\Support\Devices\Identifiers;
use App\Support\MediaInventory\Context;
use App\Support\Restrictions\Context as RestrictionContext;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MediaEffects
{
    public function prepare(#[\SensitiveParameter] array $details, User $observer, string $batch): array
    {
        foreach ($details['units'] ?? [] as $index => $unit) {
            if (! empty($unit['existing_code'])) {
                $details['units'][$index]['existing_id'] = DB::table('subjects')->where('public_code', $unit['existing_code'])->value('id');
                $eligible = $details['units'][$index]['existing_id'] && DB::table('items as medium')->join('items as parent', 'parent.owner_user_id', '=', 'medium.owner_user_id')->join('custody_current as control', 'control.subject_id', '=', 'medium.subject_id')->join('dismantling_batches as batch', 'batch.facility_party_id', '=', 'control.party_id')->where('medium.subject_id', $details['units'][$index]['existing_id'])->where('parent.subject_id', $unit['parent_id'])->where('medium.subtype', $unit['media_kind'])->where('batch.subject_id', $batch)->whereRaw('media_review_scope(?,medium.subject_id)', [$observer->id])->whereRaw('NOT batch_work_restricted(medium.subject_id) AND NOT restriction_receipt_blocked(medium.subject_id) AND NOT restriction_inspection_blocked(medium.subject_id)')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('handover_items as held')->whereColumn('held.subject_id', 'medium.subject_id')->where('held.active', true))->exists();
                if (! $eligible) {
                    throw ValidationException::withMessages(['details.units.'.$index.'.existing_code' => 'No eligible same-owner facility medium matches this observation. Resolve pending returns/transfers and retained safety restrictions through supported controls. Keep unresolved; no matched record details are disclosed.']);
                }
            }
            if (! empty($details['units'][$index]['existing_id'])) {
                $existing = DB::table('subjects as s')->join('custody_current as c', 'c.subject_id', '=', 's.id')->where('s.id', $details['units'][$index]['existing_id'])->first(['s.version', 'c.version as custody_version', 'c.last_event_id']);
                $details['units'][$index]['existing_version'] = $existing?->version;
                $details['units'][$index]['existing_custody_version'] = $existing?->custody_version;
                $details['units'][$index]['existing_custody_event'] = $existing?->last_event_id;
                $details['units'][$index]['existing_context'] = app(RestrictionContext::class)->dependencies($details['units'][$index]['existing_id']);
            }
            foreach (['measurement', 'remaining'] as $name) {
                $m = $unit[$name] ?? null;
                try {
                    $details['units'][$index][$name] = Mass::measurement(['measure_mode' => $m['mode'] ?? 'unknown', 'net_kg' => $m['net'] ?? null, 'gross_kg' => $m['gross'] ?? null, 'tare_kg' => $m['tare'] ?? null, 'measurement_source' => $m['source'] ?? null]);
                } catch (ValidationException $e) {
                    $names = ['net_kg' => 'net', 'gross_kg' => 'gross', 'tare_kg' => 'tare', 'measurement_source' => 'source'];
                    throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($messages, $field) => ['details.units.'.$index.'.'.$name.'.'.($names[$field] ?? 'mode') => $messages])->all());
                }
            }
            unset($details['units'][$index]['identifier'], $details['units'][$index]['model']);
        }

        return $details;
    }

    public function apply(User $reviewer, object $case, object $review): void
    {
        $proposal = DB::table('restriction_records')->where('id', $review->proposal_id)->firstOrFail();
        $review->technical_observed_at = $proposal->observed_at;
        $observer = User::findOrFail($proposal->actor_id);
        $details = json_decode($proposal->details, true, flags: JSON_THROW_ON_ERROR);
        $private = $proposal->private_details_ciphertext ? json_decode(Crypt::decryptString($proposal->private_details_ciphertext), true, flags: JSON_THROW_ON_ERROR) : [];
        $refs = [];
        if (in_array($case->fact_key, ['association_confirm', 'association_end'], true)) {
            $refs['inventory'] = $this->binding($reviewer, $observer, $case, $review);
        } elseif ($case->fact_key === 'discovery_supplement') {
            foreach ($details['units'] as $index => $unit) {
                $refs['units'][] = $this->discovery($reviewer, $observer, $case, $review, $unit, $private['units'][$index]);
            }
        }
        DB::table('media_review_effects')->insert(['record_id' => $review->id, 'case_id' => $case->id, 'proposal_id' => $proposal->id, 'observer_id' => $observer->id, 'action' => $case->fact_key, 'refs' => json_encode((object) $refs, JSON_THROW_ON_ERROR)]);
    }

    private function command(User $actor, string $action, string $record, string $review): string
    {
        return AdminMutation::command($actor, $action, Str::uuid()->toString(), AdminMutation::hash(['review' => $review, 'record' => $record]), $record);
    }

    private function event(User $actor, string $subject, string $aggregate, int $version, string $type, string $command, object $review, ?string $observedAt = null): string
    {
        $id = Str::uuid()->toString();
        DB::table('domain_events')->insert(['id' => $id, 'subject_id' => $subject, 'aggregate_kind' => $aggregate, 'aggregate_id' => $subject, 'aggregate_version' => $version, 'actor_id' => $actor->id, 'command_id' => $command, 'type' => $type, 'occurred_at' => $observedAt ?? $review->observed_at, 'recorded_at' => $review->recorded_at, 'safe_payload' => json_encode(['review_id' => $review->id, 'milestone' => 'Reviewed recording/provenance effect; no receipt, physical work or completion asserted.'])]);

        return $id;
    }

    private function binding(User $actor, User $observer, object $case, object $review): string
    {
        $source = DB::table('media_inventory_records')->where('id', $case->source_id)->firstOrFail();
        $entry = DB::table('media_inventory_entries')->where('id', $source->entry_id)->lockForUpdate()->firstOrFail();
        $ctx = app(Context::class);
        $row = $ctx->parent($observer, $case->subject_id);
        $snapshot = $ctx->snapshot($observer, $row);
        unset($snapshot['authorization_context_version'], $snapshot['authorization_id']);
        $current = DB::table('media_inventory_current')->where('parent_id', $case->subject_id)->firstOrFail();
        $id = Str::uuid()->toString();
        $command = $this->command($actor, 'media_inventory_review_binding:'.$case->subject_id, $id, $review->id);
        $state = $case->fact_key === 'association_confirm' ? 'confirmed' : 'association_superseded';
        DB::table('media_inventory_records')->insert($snapshot + ['id' => $id, 'parent_id' => $case->subject_id, 'version' => $current->version + 1, 'predecessor_id' => $current->record_id, 'kind' => 'review_binding', 'entry_id' => $entry->id, 'medium_id' => $entry->medium_id, 'entry_state' => $state, 'placement_ciphertext' => $source->placement_ciphertext, 'correction_review_id' => $review->id, 'observer_id' => $observer->id, 'actor_id' => $actor->id, 'checks' => '{}', 'command_id' => $command, 'event_id' => $this->event($actor, $case->subject_id, 'media_inventory', $current->version + 1, 'media_inventory_review_binding', $command, $review), 'observed_at' => $review->observed_at, 'recorded_at' => $review->recorded_at, 'reason_ciphertext' => $review->reason_ciphertext, 'attachment_id' => $review->attachment_id, 'reference_ciphertext' => $review->reference_ciphertext]);
        DB::table('media_inventory_entries')->where('id', $entry->id)->update(['state' => $state, 'record_id' => $id]);
        DB::table('media_inventory_current')->where('parent_id', $case->subject_id)->update(['version' => $current->version + 1, 'record_id' => $id]);

        return $id;
    }

    private function discovery(User $actor, User $observer, object $case, object $review, array $unit, #[\SensitiveParameter] array $private): string
    {
        $parent = DB::table('items')->where('subject_id', $unit['parent_id'])->firstOrFail();
        $batch = DB::table('dismantling_batches')->where('subject_id', $case->subject_id)->firstOrFail();
        $medium = $unit['existing_id'] ?? $unit['id'];
        $originEvent = null;
        if (empty($unit['existing_id'])) {
            $normalized = Identifiers::normalize($private['identifier'] ?? null);
            $digest = $normalized === null ? null : Identifiers::digest($normalized);
            DB::table('subjects')->insert(['id' => $medium, 'kind' => 'item', 'public_code' => 'EWC-'.strtoupper(bin2hex(random_bytes(16))), 'version' => 1, 'created_at' => now()]);
            DB::table('items')->insert(['subject_id' => $medium, 'owner_user_id' => $parent->owner_user_id, 'category_id' => DB::table('categories')->where('code', 'processor_media')->value('id'), 'subtype' => $unit['media_kind'], 'model' => $private['model'] ?? null, 'intake_class' => 'processor_derived', 'processing_state' => 'inventoried', 'data_status' => 'YES', 'identifier_kind' => $digest ? 'serial' : null, 'identifier_ciphertext' => $digest ? Crypt::encryptString(trim($private['identifier'])) : null, 'identifier_hmac' => $digest, 'identifier_key_version' => $digest ? Identifiers::KEY_VERSION : null]);
            DB::table('storage_assets')->insert(['subject_id' => $medium, 'media_kind' => $unit['media_kind']]);
            $originEvent = $this->event($actor, $medium, 'item', 1, 'media_discovered_in_batch', $review->command_id, $review, $review->technical_observed_at);
            DB::table('custody_current')->insert(['subject_id' => $medium, 'party_id' => $batch->facility_party_id, 'last_event_id' => $originEvent, 'version' => 1]);
            DB::table('media_origins')->insert(['subject_id' => $medium, 'parent_id' => $parent->subject_id, 'event_id' => $originEvent, 'origin_kind' => 'batch_discovery', 'discovery_id' => $unit['id']]);
        }
        $measurement = $unit['measurement'];
        $production = $this->batchRecord($actor, $observer, $case, $review, 'output', $medium, $measurement, $private['measurement']['source'] ?? null, $unit['parent_id']);
        DB::table('batch_outputs')->insert(['subject_id' => $medium, 'batch_id' => $batch->subject_id, 'type' => 'storage', 'material_code' => 'CANONICAL_'.strtoupper($unit['media_kind']), 'source_item_id' => $parent->subject_id, 'production_record_id' => $production, 'measurement_record_id' => $measurement['measure_mode'] ? $production : null]);
        $remaining = null;
        if ($unit['mode'] === 'reclassified') {
            $remaining = DB::table('batch_records')->where('correction_review_id', $review->id)->where('kind', 'measurement')->where('target_id', $unit['source_output_id'])->value('id');
            if (! $remaining) {
                $remaining = $this->batchRecord($actor, $observer, $case, $review, 'measurement', $unit['source_output_id'], $unit['remaining'], $private['remaining']['source'], null);
                DB::table('batch_outputs')->where('subject_id', $unit['source_output_id'])->update(['measurement_record_id' => $remaining]);
            }

        }
        DB::table('batch_media_discoveries')->insert(['id' => $unit['id'], 'review_id' => $review->id, 'proposal_id' => $review->proposal_id, 'discrepancy_id' => $case->source_id, 'batch_id' => $batch->subject_id, 'parent_id' => $parent->subject_id, 'medium_id' => $medium, 'unit_count' => count(json_decode(DB::table('restriction_records')->where('id', $review->proposal_id)->value('details'), true)['units']), 'is_new' => empty($unit['existing_id']), 'mode' => $unit['mode'], 'source_output_id' => $unit['source_output_id'] ?? null, 'production_id' => $production, 'measurement_id' => $measurement['measure_mode'] ? $production : null, 'remaining_measurement_id' => $remaining, 'observer_id' => $observer->id, 'observed_at' => $review->technical_observed_at, 'recorded_at' => $review->recorded_at]);

        return $unit['id'];
    }

    private function batchRecord(User $actor, User $observer, object $case, object $review, string $kind, string $target, array $measurement, ?string $source, ?string $parent): string
    {
        $batch = DB::table('dismantling_batches')->where('subject_id', $case->subject_id)->lockForUpdate()->firstOrFail();
        $inputs = json_decode(DB::selectOne('SELECT to_json(inputs) AS ids FROM dismantling_batches WHERE subject_id=?', [$batch->subject_id])->ids, true);
        $snapshot = DB::selectOne('SELECT batch_context(?,?,?::uuid[]) AS context', [$batch->subject_id, $observer->id, $batch->inputs])->context;
        $id = Str::uuid()->toString();
        $command = $this->command($actor, 'batch_'.$kind.':'.$batch->subject_id, $id, $review->id);
        $previous = $kind === 'measurement' ? DB::table('batch_outputs')->where('subject_id', $target)->value('measurement_record_id') : null;
        DB::table('batch_records')->insert($measurement + ['id' => $id, 'batch_id' => $batch->subject_id, 'version' => $batch->version + 1, 'predecessor_id' => $batch->record_id, 'kind' => $kind, 'inputs' => '{'.implode(',', $inputs).'}', 'detached' => '{}', 'target_id' => $target, 'previous_measurement_id' => $previous, 'output_type' => $kind === 'output' ? 'storage' : null, 'material_code' => $kind === 'output' ? 'CANONICAL_'.strtoupper(DB::table('items')->where('subject_id', $target)->value('subtype')) : null, 'source_item_id' => $parent, 'unit' => 'kg', 'measurement_source_ciphertext' => $measurement['measure_mode'] ? Crypt::encryptString($source) : null, 'checks' => $kind === 'output' ? '{"actual_output":"YES"}' : '{}', 'context' => $snapshot, 'actor_id' => $actor->id, 'observer_id' => $observer->id, 'correction_review_id' => $review->id, 'command_id' => $command, 'event_id' => $this->event($actor, $batch->subject_id, 'batch', $batch->version + 1, 'batch_'.$kind, $command, $review), 'observed_at' => $review->observed_at, 'recorded_at' => $review->recorded_at, 'reason_ciphertext' => $review->reason_ciphertext, 'attachment_id' => $review->attachment_id, 'reference_ciphertext' => $review->reference_ciphertext]);
        DB::table('dismantling_batches')->where('subject_id', $batch->subject_id)->update(['version' => $batch->version + 1, 'record_id' => $id]);

        return $id;
    }
}
