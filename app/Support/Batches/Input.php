<?php

namespace App\Support\Batches;

use App\Support\Assignments\Input as StrictInput;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Input
{
    public static function validate(#[\SensitiveParameter] array $fields, string $kind, ?UploadedFile $file): array
    {
        $rules = ['idempotency_key' => ['required', 'uuid'], 'expected_version' => ['required', 'integer', 'min:0'], 'expected_context' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'], 'observed_at' => ['required', 'date_format:Y-m-d\TH:i'], 'reason' => ['required', 'string', 'max:2000'], 'evidence_kind' => ['required', Rule::in(['attachment', 'operator_reference'])], 'reference_issuer' => ['required_if:evidence_kind,operator_reference', 'prohibited_unless:evidence_kind,operator_reference', 'nullable', 'string', 'max:120'], 'reference_code' => ['required_if:evidence_kind,operator_reference', 'prohibited_unless:evidence_kind,operator_reference', 'nullable', 'string', 'max:120'], 'reference_attested' => ['required_if:evidence_kind,operator_reference', 'prohibited_unless:evidence_kind,operator_reference', 'exclude_unless:evidence_kind,operator_reference', 'accepted']];
        if ($kind === 'draft') {
            $rules += ['inputs' => ['required', 'array', 'min:1', 'max:25'], 'inputs.*' => ['required', 'uuid', 'distinct']];
        }
        if (in_array($kind, ['commit', 'output', 'measurement'], true)) {
            $rules += ['unit' => ['required', Rule::in(['kg'])], 'measure_mode' => ['required', Rule::in($kind === 'output' ? ['unknown', 'net', 'gross_tare'] : ['net', 'gross_tare'])], 'net_kg' => ['nullable', 'string', 'max:20'], 'gross_kg' => ['nullable', 'string', 'max:20'], 'tare_kg' => ['nullable', 'string', 'max:20'], 'measurement_source' => ['nullable', 'string', 'max:1000']];
        }
        if ($kind === 'output') {
            $rules += ['output_type' => ['required', Rule::in(['material', 'component', 'residue'])], 'material_code' => ['required', 'string', 'max:120'], 'source_item_id' => ['nullable', 'uuid'], 'checks' => ['required', 'array:actual_output'], 'checks.actual_output' => ['required', Rule::in(['YES'])]];
        } elseif ($kind === 'measurement') {
            $rules += ['target_id' => ['required', 'uuid'], 'previous_measurement_id' => ['nullable', 'uuid']];
        } elseif ($kind === 'commit') {
            $rules += ['detached' => ['sometimes', 'array'], 'detached.*' => ['required', 'uuid', 'distinct'], 'checks' => ['required', 'array:actual_dismantling,all_media_detached,identity_safety']];
            foreach (['actual_dismantling', 'all_media_detached', 'identity_safety'] as $key) {
                $rules['checks.'.$key] = ['required', Rule::in(['YES'])];
            }
        } elseif (in_array($kind, ['accounted', 'discrepancy', 'authorize'], true)) {
            $keys = match ($kind) {
                'accounted' => ['all_outputs_accounted'], 'discrepancy' => ['unexpected_media'], default => ['identity_confirmed', 'safe_media', 'method_scope_known', 'no_unresolved_restriction'],
            };
            $rules['checks'] = ['required', 'array:'.implode(',', $keys)];
            foreach ($keys as $key) {
                $rules['checks.'.$key] = ['required', Rule::in(['YES'])];
            }
        }
        $fields = StrictInput::validate($fields, $rules);
        if (($fields['evidence_kind'] === 'attachment') !== ($file !== null)) {
            throw ValidationException::withMessages(['evidence' => 'Provide the selected private attachment or an accountable reference.']);
        }
        if ($file) {
            Validator::make(['evidence' => $file], ['evidence' => ['file', 'mimes:jpg,jpeg,png,pdf', 'max:5120']])->validate();
        }
        $fields['evidence_digest'] = $file ? hash_file('sha256', $file->getPathname()) : null;
        foreach (['inputs', 'detached', 'checks'] as $name) {
            $fields[$name] ??= [];
            $name === 'checks' ? ksort($fields[$name]) : sort($fields[$name]);
        }

        return $fields;
    }
}
