<?php

namespace App\Support\Refurbishment;

use App\Support\Assignments\Input as StrictInput;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Input
{
    public static function validate(#[\SensitiveParameter] array $fields, string $kind, object $item, ?UploadedFile $file): array
    {
        $rules = ['idempotency_key' => ['required', 'uuid'], 'expected_version' => ['required', 'integer', 'min:0'], 'expected_context' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'], 'observed_at' => ['required', 'date_format:Y-m-d\TH:i'], 'reason' => ['required', 'string', 'max:2000'], 'evidence_kind' => ['required', Rule::in(['attachment', 'operator_reference'])], 'reference_issuer' => ['required_if:evidence_kind,operator_reference', 'prohibited_unless:evidence_kind,operator_reference', 'nullable', 'string', 'max:120'], 'reference_code' => ['required_if:evidence_kind,operator_reference', 'prohibited_unless:evidence_kind,operator_reference', 'nullable', 'string', 'max:120'], 'reference_attested' => ['required_if:evidence_kind,operator_reference', 'prohibited_unless:evidence_kind,operator_reference', 'exclude_unless:evidence_kind,operator_reference', 'accepted']];
        if ($kind === 'work') {
            $rules += ['outcome' => ['required', Rule::in(['completed', 'failed', 'incomplete'])], 'configuration' => ['required', Rule::in(['UNCHANGED', 'CHANGED', 'UNKNOWN'])]];
        } elseif ($kind === 'test') {
            $qs = Profiles::questions($item);
            $rules += ['answers' => ['required', 'array:'.implode(',', array_keys($qs))], 'na_reasons' => ['sometimes', 'array:'.implode(',', array_keys($qs))]];
            foreach ($qs as $code => $q) {
                $rules['answers.'.$code] = ['required', Rule::in($q['values'])];
                $rules['na_reasons.'.$code] = ['nullable', 'string', 'max:1000'];
            }
        } elseif (in_array($kind, ['withdraw', 'exception', 'acknowledge'], true)) {
            $rules['authorization_id'] = ['required', 'uuid'];
            if ($kind === 'acknowledge') {
                $rules['received'] = ['required', 'array', 'min:1'];
                $rules['received.*'] = ['required', 'uuid', 'distinct'];
                $rules['actual_receipt'] = ['required', 'accepted'];
            }
        }
        $fields = StrictInput::validate($fields, $rules);
        foreach ($fields['answers'] ?? [] as $key => $value) {
            if ($value === 'NOT_APPLICABLE' && empty(trim($fields['na_reasons'][$key] ?? ''))) {
                throw ValidationException::withMessages(['na_reasons.'.$key => 'Explain why this explicitly permitted test is not applicable.']);
            }
        }
        if (($fields['evidence_kind'] === 'attachment') !== ($file !== null)) {
            throw ValidationException::withMessages(['evidence' => 'Provide the selected private attachment or an accountable reference for this action.']);
        }
        if ($file) {
            Validator::make(['evidence' => $file], ['evidence' => ['file', 'mimes:jpg,jpeg,png,pdf', 'max:5120']])->validate();
        }
        $fields['evidence_digest'] = $file ? hash_file('sha256', $file->getPathname()) : null;
        if (isset($fields['received'])) {
            sort($fields['received']);
        }
        $fields['answers'] ??= [];
        ksort($fields['answers']);

        return $fields;
    }
}
