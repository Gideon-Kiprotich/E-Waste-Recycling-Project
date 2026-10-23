<?php

namespace App\Support\Downstream;

use App\Support\Assignments\Input as StrictInput;
use App\Support\Batches\Input as BatchInput;
use App\Support\Batches\Mass;
use App\Support\NairobiTime;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Input
{
    public static function validate(#[\SensitiveParameter] array $fields, string $kind, ?UploadedFile $file): array
    {
        $fields = NairobiTime::normalize($fields);
        $base = array_flip(['idempotency_key', 'expected_version', 'expected_context', 'observed_at', 'reason', 'evidence_kind', 'reference_issuer', 'reference_code', 'reference_attested']);
        $rules = ['attested' => ['required', 'accepted']];
        if ($kind === 'profile') {
            $rules += ['facility_id' => ['required', 'uuid'], 'output_type' => ['required', Rule::in(['material', 'component', 'residue', 'storage'])], 'material_key' => ['required', 'string', 'max:120'], 'hazards' => ['required', Rule::in(['NONE'])], 'approved' => ['required', Rule::in(['1', '0'])]];
        } elseif ($kind === 'measurement_dispute') {
            $rules += ['shipment_id' => ['sometimes', 'uuid']];
        } elseif ($kind === 'draft') {
            $rules += ['output_id' => ['required', 'uuid'], 'profile_record_id' => ['required', 'uuid'], 'receiver_actor_id' => ['required', 'uuid'], 'kg' => ['required', 'string'], 'hazards' => ['required', Rule::in(['NONE'])]];
        } elseif (! in_array($kind, ['close', 'measurement_dispute'], true)) {
            $rules += ['kg' => ['required', 'string']];
            $rules += $kind === 'source_loss' ? ['output_id' => ['required', 'uuid']] : ['shipment_id' => ['required', 'uuid']];
            if (in_array($kind, ['accept', 'refuse', 'shortage', 'dispute', 'complete', 'downstream_loss'], true)) {
                $rules['related_record_id'] = ['required', 'uuid'];
            }
            if ($kind === 'accept') {
                $rules['identity_code'] = ['required', 'string', 'regex:/\AEWC-[A-F0-9]{32}\z/'];
                $rules['condition'] = ['required', Rule::in(['intact', 'damaged'])];
            }
            if ($kind === 'complete') {
                $rules += ['outcome' => ['required', Rule::in(['recovered', 'disposed', 'controlled_storage'])], 'issuer' => ['required', 'string', 'max:200']];
            }
        }
        $custom = StrictInput::validate(array_diff_key($fields, $base), $rules);
        $common = BatchInput::validate(array_intersect_key($fields, $base), 'downstream', $file);
        unset($common['inputs'], $common['detached'], $common['checks']);
        if (isset($custom['kg'])) {
            $milli = Mass::milli($custom['kg'], 'kg');
            if ($milli <= 0) {
                throw ValidationException::withMessages(['kg' => 'Record a positive measured quantity in kg.']);
            }
            $custom['kg'] = Mass::decimal($milli);
        }

        return $common + $custom;
    }
}
