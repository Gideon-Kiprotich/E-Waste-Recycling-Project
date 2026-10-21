<?php

namespace App\Support\Restrictions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MediaReview
{
    public static function blockers(object $source): array
    {
        if ($source->source_kind !== 'media_correction') {
            return [];
        }
        if (str_starts_with($source->fact_key, 'discovery')) {
            $batch = DB::table('dismantling_batches')->where('subject_id', $source->subject_id)->firstOrFail();
            $messages = [];
            if ($batch->state !== 'processing' || $batch->input_measurement_id !== $batch->commit_record_id) {
                $messages[] = 'Recovery requires an on-site processing batch with its original measured input. Changed input mass or a terminal batch is outside this supplement contract.';
            }
            if (DB::table('downstream_records')->where('batch_id', $source->subject_id)->whereIn('kind', ['dispatch', 'source_loss', 'downstream_loss', 'accept', 'refuse', 'shortage', 'dispute', 'complete', 'close', 'measurement_dispute'])->exists()) {
                $messages[] = 'Dispatch, loss, recipient or closure history exists. On-site recovery is unavailable; retain the discrepancy for the separate downstream correction contract.';
            }
            if (DB::table('downstream_shipments as h')->where('h.batch_id', $source->subject_id)->whereRaw('downstream_reserved(h.id)>0')->exists()) {
                $messages[] = 'Withdraw every existing reservation through Downstream movements, then reload and record fresh findings. This desk cannot release reservations.';
            }

            return $messages;
        }
        if ($source->fact_key === 'association_end') {
            $entry = DB::table('media_inventory_records as r')->join('media_inventory_entries as e', 'e.id', '=', 'r.entry_id')->join('items as i', 'i.subject_id', '=', 'e.medium_id')->where('r.id', $source->source_id)->first(['i.subject_id', 'i.intake_class', 'i.subtype']);
            if (! $entry || $entry->intake_class !== 'standalone_part' || ! in_array($entry->subtype, ['hdd', 'ssd'], true) || ! DB::table('first_receipts')->where('subject_id', $entry->subject_id)->exists() || DB::table('media_origins')->where('subject_id', $entry->subject_id)->exists()) {
                return ['Ending an erroneous association requires an independently received standalone HDD/SSD. A derived identity whose only origin is the disputed parent stays held; origin reconstruction, replacement and re-parenting are unavailable.'];
            }
        }
        if ($source->fact_key === 'impact_continuity') {
            $type = DB::table('restriction_impacts as i')->join('domain_events as e', 'e.id', '=', 'i.target_id')->where('i.id', $source->source_id)->value('e.type');
            if (! in_array($type, ['sanitization_attempt', 'refurbishment_work', 'dismantled_in_batch', 'media_detached_in_batch', 'batch_commit'], true)) {
                return ['This completed obligation requires receipt, movement, owner-return or downstream recovery outside Phase 15. Investigation cannot reverse that completed history.'];
            }
            if (DB::selectOne('SELECT media_impact_fulfilled(?) AS current', [$source->source_id])->current) {
                return ['Current fulfillment is recorded for this exact obligation. Independent accounting, data, safety and movement gates still apply.'];
            }
            if (DB::table('restriction_impact_reviews')->where('impact_id', $source->source_id)->where('stage', 'fulfilled')->exists()) {
                return ['Historical fulfillment is retained, but its dependency proof changed. An Administrator must open a fresh linked continuity review; do not revive old authority.'];
            }
        }

        return [];
    }

    public const DISCOVERY_CHECKS = ['distinct_units' => 'Every physical unit is distinguished and counted', 'source_owner_control' => 'Source, accountability owner and facility control are evidenced', 'safe_observation' => 'Supported HDD/SSD identity and safety are known', 'included_input_mass' => 'The original measured input already included these units', 'accounting_known' => 'The selected accounting mode and mass overlap are evidenced', 'no_movement' => 'No physical transfer, replacement or invented detachment is asserted', 'onsite_only' => 'No dispatch, loss, receipt or closure history exists', 'all_units_accounted' => 'Every reported discovery unit is included'];

    public const IMPACT_CHECKS = ['same_identity' => 'The same physical identities remain', 'original_obligation' => 'The original affected obligation has been investigated', 'current_control' => 'Current capable facility control is evidenced', 'history_preserved' => 'Completed history and original evidence remain unchanged', 'no_physical_change' => 'No movement, replacement or destruction reversal is concealed', 'supported_fulfillment' => 'Only the supported continuity and fulfillment steps are proposed'];

    public static function label(?string $type, string $kind): string
    {
        return $kind === 'approve' ? match ($type) {
            'discovery_supplement' => 'Approve evidenced discovery supplement',
            'impact_continuity' => 'Approve obligation continuity',
            default => Context::LABELS[$kind],
        } : Context::LABELS[$kind];
    }

    public static function checks(?string $type): array
    {
        return match ($type) {
            'discovery_supplement' => self::DISCOVERY_CHECKS,
            'impact_continuity' => self::IMPACT_CHECKS,
            default => Context::CHECKS,
        };
    }

    public static function details(#[\SensitiveParameter] array $fields, string $type, string $kind): array
    {
        $rules = [];
        if ($kind === 'owner_clarify') {
            $rules = ['no_handover' => ['required', Rule::in(['YES'])], 'manifest' => ['required', 'array', 'min:1'], 'manifest.*' => ['required', 'uuid', 'distinct']];
        } elseif ($kind === 'proposal' && $type === 'discovery_supplement') {
            $rules = ['count' => ['required', 'integer', 'min:1', 'max:10'], 'units' => ['required', 'array', 'min:1', 'max:10'], 'units.*' => ['required', 'array:id,parent_id,existing_code,media_kind,identifier,model,mode,source_output_id,measurement,remaining'], 'units.*.id' => ['required', 'uuid', 'distinct'], 'units.*.parent_id' => ['required', 'uuid'], 'units.*.existing_code' => ['nullable', 'string', 'regex:/\AEWC-[A-F0-9]{32}\z/'], 'units.*.media_kind' => ['required', Rule::in(['hdd', 'ssd'])], 'units.*.identifier' => ['nullable', 'string', 'max:100', 'regex:/\A[A-Za-z0-9\s-]*\z/'], 'units.*.model' => ['nullable', 'string', 'max:120'], 'units.*.mode' => ['required', Rule::in(['unaccounted', 'reclassified'])], 'units.*.source_output_id' => ['nullable', 'uuid']];
            foreach (['measurement', 'remaining'] as $name) {
                $rules['units.*.'.$name] = ['nullable', 'array:mode,net,gross,tare,source'];
                $rules['units.*.'.$name.'.mode'] = ['required_with:units.*.'.$name, Rule::in(['net', 'gross_tare'])];
                foreach (['net', 'gross', 'tare'] as $quantity) {
                    $rules['units.*.'.$name.'.'.$quantity] = ['nullable', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,3})?\z/'];
                }
                $rules['units.*.'.$name.'.source'] = ['required_with:units.*.'.$name, 'string', 'max:1500'];
            }
        } elseif ($kind === 'proposal' && $type === 'discovery_error') {
            $rules = ['accounted_media' => ['sometimes', 'array', 'max:10'], 'accounted_media.*' => ['required', 'uuid', 'distinct']];
        }
        if (array_diff(array_keys($fields), array_filter(array_keys($rules), fn ($k) => ! str_contains($k, '.')))) {
            throw ValidationException::withMessages(['details' => 'Use only the fields for this exact typed correction.']);
        }
        foreach ($fields['units'] ?? [] as $index => $unit) {
            foreach (['measurement', 'remaining'] as $name) {
                if (isset($unit[$name]) && is_array($unit[$name]) && ! array_filter($unit[$name], fn ($v) => $v !== null && $v !== '')) {
                    unset($fields['units'][$index][$name]);
                }
            }
        }
        $validator = Validator::make($fields, $rules);
        if ($validator->fails()) {
            throw ValidationException::withMessages(collect($validator->errors()->messages())->mapWithKeys(fn ($messages, $field) => ['details.'.$field => $messages])->all());
        }
        $value = $validator->validated();
        if ($kind === 'proposal' && $type === 'discovery_error') {
            $value['accounted_media'] ??= [];
        }

        if (isset($value['manifest'])) {
            sort($value['manifest']);
        }
        if (isset($value['units'])) {
            if (count($value['units']) !== (int) $value['count']) {
                throw ValidationException::withMessages(['details.count' => 'Every distinguished unit must be included; partial provenance cannot clear the report.']);
            }
            foreach ($value['units'] as $unit) {
                if ($unit['mode'] === 'reclassified' && (empty($unit['source_output_id']) || empty($unit['measurement']) || empty($unit['remaining']))) {
                    throw ValidationException::withMessages(['details.units' => 'Reclassification needs the original on-site output and both actual measurements.']);
                }
                if ($unit['mode'] === 'unaccounted' && (! empty($unit['source_output_id']) || ! empty($unit['remaining']))) {
                    throw ValidationException::withMessages(['details.units' => 'Previously unaccounted output cannot also reclassify an aggregate.']);
                }
                if (! empty($unit['existing_code']) && (! empty($unit['identifier']) || ! empty($unit['model']))) {
                    throw ValidationException::withMessages(['details.units' => 'Recognize an existing identity without supplying replacement identifiers or metadata.']);
                }
            }
            usort($value['units'], fn ($a, $b) => strcmp($a['id'], $b['id']));
        }

        return $value;
    }
}
