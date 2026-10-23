<?php

namespace App\Support\Sanitization;

use App\Support\Assignments\Input as FormInput;
use App\Support\NairobiTime;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Input
{
    public static function validate(#[\SensitiveParameter] array $fields, string $kind, #[\SensitiveParameter] ?UploadedFile $file): array
    {
        $fields = NairobiTime::normalize($fields);
        $outcomes = match ($kind) {
            'attempt' => ['success', 'failed', 'incomplete', 'unverifiable'],
            'verification' => ['satisfactory', 'unsatisfactory', 'unverifiable'],
            default => ['accepted', 'rejected'],
        };
        $rules = ['idempotency_key' => ['required', 'uuid'], 'expected_version' => ['required', 'integer', 'min:0'], 'expected_context' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'], 'observed_at' => ['required', 'date_format:Y-m-d\TH:i'], 'reason' => ['required', 'string', 'max:2000'], 'outcome' => ['required', Rule::in($outcomes)], 'evidence_kind' => ['nullable', Rule::in(['attachment', 'operator_reference'])], 'reference_issuer' => ['required_if:evidence_kind,operator_reference', 'prohibited_unless:evidence_kind,operator_reference', 'nullable', 'string', 'max:120'], 'reference_code' => ['required_if:evidence_kind,operator_reference', 'prohibited_unless:evidence_kind,operator_reference', 'nullable', 'string', 'max:120'], 'reference_attested' => ['required_if:evidence_kind,operator_reference', 'prohibited_unless:evidence_kind,operator_reference', 'exclude_unless:evidence_kind,operator_reference', 'accepted']];
        if ($kind === 'attempt') {
            $rules['technique'] = ['required', Rule::in(array_keys(Policy::definition()['techniques']))];
            foreach (['executor', 'tool', 'tool_version', 'scope', 'procedure'] as $field) {
                $rules[$field] = ['nullable', 'string', 'max:1500'];
            }
        } else {
            $checks = Policy::checks($kind);
            $rules['checks'] = ['required', 'array:'.implode(',', array_keys($checks))];
            foreach ($checks as $code => $label) {
                $rules['checks.'.$code] = ['required', Rule::in(['YES', 'NO', 'UNKNOWN'])];
            }
        }
        $fields = FormInput::validate($fields, $rules);
        if ($kind !== 'attempt' || $fields['outcome'] === 'success') {
            $required = ['evidence_kind'];
            if ($kind === 'attempt') {
                $required = array_merge($required, ['executor', 'tool', 'scope', 'procedure']);
                if (Policy::definition()['techniques'][$fields['technique']]['software_version']) {
                    $required[] = 'tool_version';
                }
            }
            Validator::make($fields, array_fill_keys($required, ['required', 'string']))->validate();
        }
        if (($fields['evidence_kind'] ?? null) === 'attachment' && ! $file || $file && ($fields['evidence_kind'] ?? null) !== 'attachment') {
            throw ValidationException::withMessages(['evidence' => 'Provide the selected private attachment, or choose an accountable operator reference.']);
        }
        if ($file) {
            Validator::make(['evidence' => $file], ['evidence' => ['file', 'mimes:jpg,jpeg,png,pdf', 'max:5120']])->validate();
        }
        $fields['evidence_digest'] = $file ? hash_file('sha256', $file->getPathname()) : null;
        $fields['checks'] ??= [];
        ksort($fields['checks']);
        foreach (['executor', 'tool', 'tool_version', 'scope', 'procedure', 'reference_issuer', 'reference_code', 'evidence_kind'] as $name) {
            $fields[$name] ??= null;
        }

        return $fields;
    }
}
