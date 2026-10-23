<?php

namespace App\Support\Handovers;

use App\Support\Assignments\Input as FormInput;
use App\Support\NairobiTime;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Input
{
    public static function validate(#[\SensitiveParameter] array $fields, ?UploadedFile $evidence, string $kind): array
    {
        $fields = NairobiTime::normalize($fields);
        $rules = [
            'idempotency_key' => ['required', 'uuid'],
            'observed_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'evidence_kind' => ['required', Rule::in(['attachment', 'operator_reference'])],
            'reference_issuer' => ['required_if:evidence_kind,operator_reference', 'prohibited_unless:evidence_kind,operator_reference', 'nullable', 'string', 'max:120'],
            'reference_code' => ['required_if:evidence_kind,operator_reference', 'prohibited_unless:evidence_kind,operator_reference', 'nullable', 'string', 'max:120', 'regex:/\A[A-Za-z0-9][A-Za-z0-9 ._\/\-]{0,119}\z/'],
            'reference_attested' => ['required_if:evidence_kind,operator_reference', 'prohibited_unless:evidence_kind,operator_reference', 'exclude_unless:evidence_kind,operator_reference', 'accepted'],
        ];
        if ($kind === 'initiated') {
            $rules += ['recipient_ref' => ['required', FormInput::referenceRule()], 'destination_version' => ['required', 'integer', 'min:1'], 'operator_version' => ['required', 'integer', 'min:1'], 'subjects' => ['required', 'array', 'min:1', 'max:25'], 'subjects.*' => ['required', 'distinct', FormInput::referenceRule()], 'reason' => ['required', 'string', 'max:1000'], 'sender_attested' => ['required', 'accepted']];
        } else {
            $outcomes = match ($kind) {
                'acknowledged' => ['pending', 'accepted', 'missing', 'damaged', 'mismatch', 'refused'],
                'withdrawn' => ['pending', 'withdrawn'],
                default => ['pending', 'closed_no_transfer', 'review_continues'],
            };
            $rules += ['expected_version' => ['required', 'integer', 'min:1'], 'items' => ['required', 'array', 'min:1', 'max:25'], 'items.*' => ['array:id,code,outcome,attested,reason'], 'items.*.id' => ['required', 'uuid', 'distinct'], 'items.*.code' => ['required', 'string', 'regex:/\AEWC-[A-F0-9]{32}\z/'], 'items.*.outcome' => ['required', Rule::in($outcomes)], 'items.*.attested' => ['nullable', 'boolean'], 'items.*.reason' => ['nullable', 'string', 'max:1000']];
        }
        $fields = FormInput::validate($fields, $rules);
        if ($kind === 'initiated') {
            sort($fields['subjects']);
        } else {
            $fields['items'] = array_values(array_filter($fields['items'], fn ($r) => $r['outcome'] !== 'pending'));
            if (! $fields['items']) {
                throw ValidationException::withMessages(['items' => 'Select at least one explicit outcome. Omitted items remain unchanged.']);
            }
            foreach ($fields['items'] as &$row) {
                $row['attested'] = (bool) ($row['attested'] ?? false);
                if (! is_string($row['reason'] ?? null) || trim($row['reason']) === '' || $row['attested'] !== in_array($row['outcome'], ['accepted', 'closed_no_transfer'], true)) {
                    throw ValidationException::withMessages(['items' => 'Give each selected item a private comparison/reason. Attest only actual acceptance or evidence-backed closure without transfer.']);
                }
                ksort($row);
            }unset($row);
            usort($fields['items'], fn ($a, $b) => strcmp($a['id'], $b['id']));
        }
        if (($fields['evidence_kind'] === 'attachment') !== ($evidence !== null)) {
            throw ValidationException::withMessages(['evidence' => 'Provide one private attachment or an accountable operator reference.']);
        }
        if ($evidence) {
            Validator::make(['evidence' => $evidence], ['evidence' => ['file', 'mimes:jpg,jpeg,png,pdf', 'max:5120']])->validate();
        }
        $fields['evidence_digest'] = $evidence ? hash_file('sha256', $evidence->getPathname()) : null;

        return $fields;
    }
}
