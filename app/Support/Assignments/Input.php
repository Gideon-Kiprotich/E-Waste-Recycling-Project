<?php

namespace App\Support\Assignments;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class Input
{
    public static function validate(#[\SensitiveParameter] array $fields, array $rules): array
    {
        if (array_diff(array_keys($fields), array_keys($rules))) {
            throw ValidationException::withMessages(['submission' => 'Unsupported fields. Reload the form and use only its permitted controls.']);
        }

        return Validator::make($fields, $rules)->validate();
    }

    public static function reference(string $value): array
    {
        [$id, $version] = explode(':', $value);

        return [$id, (int) $version];
    }

    public static function referenceRule(): string
    {
        return 'regex:/\A[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}:[1-9][0-9]{0,17}\z/';
    }

    public static function safe(#[\SensitiveParameter] array $fields): array
    {
        return array_filter(array_intersect_key($fields, array_flip(['idempotency_key', 'expected_version', 'expected_request_version', 'expected_review_id', 'assignment_id', 'operation', 'membership_ref', 'facility_ref', 'organization_ref'])), fn ($v) => is_string($v) && strlen($v) <= 100);
    }
}
