<?php

namespace App\Support\Receipts;

use App\Support\Assignments\Input as FormInput;
use App\Support\NairobiTime;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Input
{
    public static function validate(#[\SensitiveParameter] array $fields, ?UploadedFile $evidence): array
    {
        $fields = NairobiTime::normalize($fields);
        $fields = FormInput::validate($fields, [
            'expected_version' => ['required', 'integer', 'min:1'], 'assignment_id' => ['nullable', 'uuid'], 'expected_assignment_version' => ['nullable', 'integer', 'min:1'], 'route_id' => ['nullable', 'uuid'],
            'observed_at' => ['required', 'date_format:Y-m-d\TH:i'], 'idempotency_key' => ['required', 'uuid'],
            'evidence_kind' => ['required', Rule::in(['attachment', 'operator_reference'])],
            'reference_issuer' => ['required_if:evidence_kind,operator_reference', 'prohibited_unless:evidence_kind,operator_reference', 'nullable', 'string', 'max:120'],
            'reference_code' => ['required_if:evidence_kind,operator_reference', 'prohibited_unless:evidence_kind,operator_reference', 'nullable', 'string', 'max:120', 'regex:/\A[A-Za-z0-9][A-Za-z0-9 ._\/\-]{0,119}\z/'],
            'reference_attested' => ['required_if:evidence_kind,operator_reference', 'prohibited_unless:evidence_kind,operator_reference', 'exclude_unless:evidence_kind,operator_reference', 'accepted'],
            'items' => ['required', 'array', 'min:1', 'max:25'],
            'items.*' => ['array:request_item_id,code,outcome,attested,observation'],
            'items.*.request_item_id' => ['required', 'uuid', 'distinct'],
            'items.*.code' => ['required', 'string', 'regex:/\AEWC-[A-F0-9]{32}\z/', 'distinct'],
            'items.*.outcome' => ['required', Rule::in(['untouched', 'received', 'absent', 'refused', 'mismatch', 'safety_hold'])],
            'items.*.attested' => ['nullable', 'boolean'],
            'items.*.observation' => ['nullable', 'string', 'max:1000'],
        ]);
        $fields['items'] = array_values(array_filter($fields['items'], fn ($row) => $row['outcome'] !== 'untouched'));
        if (! $fields['items']) {
            throw ValidationException::withMessages(['items' => 'Choose at least one explicit item outcome. Untouched items remain not attempted.']);
        }
        foreach ($fields['items'] as $row) {
            if (! is_string($row['observation'] ?? null) || trim($row['observation']) === '') {
                throw ValidationException::withMessages(['items' => 'Describe the physical comparison or the reason for each attempted outcome.']);
            }
            if (($row['outcome'] === 'received') !== (bool) ($row['attested'] ?? false)) {
                throw ValidationException::withMessages(['items' => 'Attest physical comparison and actual receipt only for each received item. A mismatch or safety hold cannot be received.']);
            }
        }
        if (($fields['evidence_kind'] === 'attachment') !== ($evidence !== null)) {
            throw ValidationException::withMessages(['evidence' => 'Provide one private attachment, or choose an accountable operator reference.']);
        }
        if ($evidence) {
            Validator::make(['evidence' => $evidence], ['evidence' => ['file', 'mimes:jpg,jpeg,png,pdf', 'max:5120']])->validate();
        }
        usort($fields['items'], fn ($a, $b) => strcmp($a['request_item_id'], $b['request_item_id']));
        foreach ($fields['items'] as &$row) {
            $row['attested'] = (bool) ($row['attested'] ?? false);
            ksort($row);
        }unset($row);
        $fields['evidence_digest'] = $evidence ? hash_file('sha256', $evidence->getPathname()) : null;

        return $fields;
    }
}
