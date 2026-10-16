<?php

namespace App\Support\Assessments;

use App\Support\Assignments\Input as FormInput;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Input
{
    public static function validate(#[\SensitiveParameter] array $fields, array $schema, #[\SensitiveParameter] ?UploadedFile $file, bool $decision): array
    {
        $rules = ['idempotency_key' => ['required', 'uuid'], 'expected_version' => ['required', 'integer', 'min:0'], 'expected_subject_version' => ['required', 'integer', 'min:1'], 'observed_at' => ['required', 'date_format:Y-m-d\TH:i'], 'reason' => ['required', 'string', 'max:2000']];
        if ($decision) {
            $rules += ['assessment_id' => ['required', 'uuid'], 'pathway' => ['required', Rule::in(['refurbish', 'recycle', 'hold'])]];
            if ($file) {
                throw ValidationException::withMessages(['evidence' => 'Decisions reference the frozen inspection evidence; do not attach a replacement.']);
            }
        } else {
            $rules += ['answers' => ['required', 'array:'.implode(',', array_keys($schema['questions']))], 'na_reasons' => ['sometimes', 'array:'.implode(',', array_keys(array_filter($schema['questions'], fn ($q) => in_array('NOT_APPLICABLE', $q['values'], true))))], 'evidence_kind' => ['required', Rule::in(['attachment', 'operator_reference'])], 'reference_issuer' => ['required_if:evidence_kind,operator_reference', 'prohibited_unless:evidence_kind,operator_reference', 'nullable', 'string', 'max:120'], 'reference_code' => ['required_if:evidence_kind,operator_reference', 'prohibited_unless:evidence_kind,operator_reference', 'nullable', 'string', 'max:120'], 'reference_attested' => ['required_if:evidence_kind,operator_reference', 'prohibited_unless:evidence_kind,operator_reference', 'exclude_unless:evidence_kind,operator_reference', 'accepted']];
            foreach ($schema['questions'] as $code => $q) {
                $rules['answers.'.$code] = ['required', Rule::in($q['values'])];
                if (in_array('NOT_APPLICABLE', $q['values'], true)) {
                    $rules['na_reasons.'.$code] = ['required_if:answers.'.$code.',NOT_APPLICABLE', 'nullable', 'string', 'max:500'];
                }
            }
        }
        $fields = FormInput::validate($fields, $rules);
        if (! $decision) {
            if (($fields['evidence_kind'] === 'attachment') !== ($file !== null)) {
                throw ValidationException::withMessages(['evidence' => 'Provide one private attachment or accountable operator reference.']);
            }
            if ($file) {
                Validator::make(['evidence' => $file], ['evidence' => ['file', 'mimes:jpg,jpeg,png,pdf', 'max:5120']])->validate();
            }
            $fields['evidence_digest'] = $file ? hash_file('sha256', $file->getPathname()) : null;
            $fields['na_reasons'] ??= [];
            ksort($fields['answers']);
            ksort($fields['na_reasons']);
        }

        return $fields;
    }
}
