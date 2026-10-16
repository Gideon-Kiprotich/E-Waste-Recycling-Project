<?php

namespace App\Support\MediaInventory;

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
        if (in_array($kind, ['identify', 'link', 'unresolved', 'correct'], true)) {
            $rules['entry_id'] = [$kind === 'correct' ? 'required' : 'nullable', 'uuid'];
            $rules['placement'] = ['required', 'string', 'max:1500'];
        }
        if ($kind === 'identify') {
            $rules['media_kind'] = ['required', Rule::in(['hdd', 'ssd', 'other_nonvolatile'])];
            $rules['model'] = ['nullable', 'string', 'max:120'];
            $rules['identifier'] = ['nullable', 'string', 'max:100', 'regex:/\A[A-Za-z0-9\s-]*\z/'];
        } elseif ($kind === 'link') {
            $rules['existing_code'] = ['required', 'string', 'max:50'];
        } elseif ($kind === 'correct') {
            $rules['entry_state'] = ['required', Rule::in(['unresolved', 'not_present', 'disputed'])];
        } elseif ($kind === 'attest') {
            $rules['na_justification'] = ['nullable', 'string', 'max:1500'];
            $rules['completeness'] = ['required', Rule::in(['YES', 'NO', 'UNKNOWN'])];
        } elseif ($kind === 'authorize') {
            $rules['medium_id'] = ['required', 'uuid'];
            $rules['expected_authorization_version'] = ['required', 'integer', 'min:0'];
        }
        if ($kind === 'unresolved') {
            $rules['checks'] = ['sometimes', 'array', 'size:0'];
        }
        if ($checks = Context::checks($kind)) {
            $rules['checks'] = ['required', 'array:'.implode(',', array_keys($checks))];
            foreach ($checks as $key => $label) {
                $rules['checks.'.$key] = ['required', Rule::in(['YES', 'NO', 'UNKNOWN'])];
            }
        }
        $fields = StrictInput::validate($fields, $rules);
        if (($fields['evidence_kind'] === 'attachment') !== ($file !== null)) {
            throw ValidationException::withMessages(['evidence' => 'Provide the selected private attachment or choose an accountable operator reference.']);
        }
        if ($file) {
            Validator::make(['evidence' => $file], ['evidence' => ['file', 'mimes:jpg,jpeg,png,pdf', 'max:5120']])->validate();
        }
        $fields['evidence_digest'] = $file ? hash_file('sha256', $file->getPathname()) : null;
        $fields['checks'] ??= [];
        ksort($fields['checks']);

        return $fields;
    }
}
