<?php

namespace App\Support\Restrictions;

use App\Support\Assignments\Input as StrictInput;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Input
{
    public static function validate(#[\SensitiveParameter] array $fields, string $kind, ?UploadedFile $file, ?string $type = null): array
    {
        $rules = ['idempotency_key' => ['required', 'uuid'], 'expected_version' => ['required', 'integer', 'min:0'], 'expected_source_version' => ['required', 'integer', 'min:1'], 'expected_restriction_version' => ['required', 'integer', 'min:0'], 'expected_context' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'], 'observed_at' => ['required', 'date_format:Y-m-d\TH:i'], 'reason' => ['required', 'string', 'max:2000'], 'evidence_kind' => ['required', Rule::in(['none', 'attachment', 'operator_reference'])], 'reference_issuer' => ['required_if:evidence_kind,operator_reference', 'prohibited_unless:evidence_kind,operator_reference', 'nullable', 'string', 'max:120'], 'reference_code' => ['required_if:evidence_kind,operator_reference', 'prohibited_unless:evidence_kind,operator_reference', 'nullable', 'string', 'max:120'], 'reference_attested' => ['required_if:evidence_kind,operator_reference', 'prohibited_unless:evidence_kind,operator_reference', 'exclude_unless:evidence_kind,operator_reference', 'accepted']];
        if ($kind === 'assign') {
            $rules += ['assigned_to' => ['required', 'uuid'], 'pre_receipt' => ['required', 'boolean'], 'allowed_evidence' => ['sometimes', 'array', 'max:1'], 'allowed_evidence.*' => ['required', 'uuid', 'distinct']];
        } elseif ($kind === 'proposal' || (DownstreamReview::applies($type) && $kind === 'clarify')) {
            $rules += ['original_value' => ['required', 'string', 'max:50'], 'proposed_value' => ['required', 'string', 'max:50'], 'identity_code' => ['required', 'string', 'regex:/\AEWC-[A-F0-9]{32}\z/'], 'basis' => ['required', 'string', 'max:2000'], 'checks' => ['required', 'array:'.implode(',', array_keys(Context::CHECKS))]];
            $rules['checks'] = ['required', 'array:'.implode(',', array_keys(MediaReview::checks($type)))];
            foreach (MediaReview::checks($type) as $key => $label) {
                $rules['checks.'.$key] = ['required', Rule::in(['YES', 'NO', 'UNKNOWN'])];
            }
        } elseif (in_array($kind, ['approve', 'fulfill'], true)) {
            $rules['proposal_id'] = ['required', 'uuid'];
        }
        if ($type !== null && in_array($kind, DownstreamReview::applies($type) ? ['proposal', 'clarify', 'assign'] : ['proposal', 'owner_clarify'], true)) {
            $rules['details'] = ['sometimes', 'array'];
        }
        $fields = StrictInput::validate($fields, $rules);
        if ((in_array($kind, ['proposal', 'approve', 'fulfill', 'owner_clarify'], true) || (DownstreamReview::applies($type) && $kind === 'clarify')) && $fields['evidence_kind'] === 'none') {
            throw ValidationException::withMessages(['evidence_kind' => 'Technical findings and review require private evidence or an accountable issuer/reference.']);
        }
        if (($fields['evidence_kind'] === 'attachment') !== ($file !== null)) {
            throw ValidationException::withMessages(['evidence' => 'Provide the selected private attachment or an accountable evidence reference.']);
        }
        if ($file) {
            Validator::make(['evidence' => $file], ['evidence' => ['file', 'mimes:jpg,jpeg,png,pdf', 'max:5120']])->validate();
        }
        $fields['evidence_digest'] = $file ? hash_file('sha256', $file->getPathname()) : null;
        if (isset($fields['checks'])) {
            ksort($fields['checks']);
        }
        if (isset($fields['allowed_evidence'])) {
            sort($fields['allowed_evidence']);
        }
        if ($type !== null && in_array($kind, DownstreamReview::applies($type) ? ['proposal', 'clarify', 'assign'] : ['proposal', 'owner_clarify'], true)) {
            $fields['details'] = MediaReview::details($fields['details'] ?? [], $type, $kind);
        }

        return $fields;
    }
}
