<?php

namespace App\Support\Collection;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Input
{
    public static function safeChange(#[\SensitiveParameter] array $fields): array
    {
        $safe = [];
        foreach (['operation', 'preferred_date', 'idempotency_key', 'expected_version'] as $key) {
            $value = $fields[$key] ?? null;
            if (! is_string($value) && ! is_int($value)) {
                continue;
            }
            $value = (string) $value;
            $allowed = match ($key) {
                'operation' => in_array($value, ['cancel', 'date'], true),
                'preferred_date' => (bool) preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $value),
                'idempotency_key' => Str::isUuid($value),
                'expected_version' => (bool) preg_match('/\A[1-9][0-9]{0,17}\z/', $value),
            };
            if ($allowed) {
                $safe[$key] = $value;
            }
        }
        if (is_array($fields['items'] ?? null)) {
            $safe['items'] = array_values(array_unique(array_filter(array_slice($fields['items'], 0, 25), fn ($id) => is_string($id) && Str::isUuid($id))));
        }

        return $safe;
    }

    public static function validate(#[\SensitiveParameter] array $fields, int $step): array
    {
        if ($step === 1 && is_array($fields['estimated_kg'] ?? null) && is_array($fields['items'] ?? null)) {
            foreach ($fields['estimated_kg'] as $id => $mass) {
                if (! in_array($id, $fields['items'], true) && ($mass === '' || $mass === null)) {
                    unset($fields['estimated_kg'][$id]);
                }
            }
        }
        $rules = match ($step) {
            1 => ['items' => ['required', 'array', 'min:1', 'max:25'], 'items.*' => ['required', 'uuid', 'distinct'], 'estimated_kg' => ['required', 'array'], 'estimated_kg.*' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:999999999.999']],
            2 => ['mode' => ['required', Rule::in(['pickup', 'drop_off'])]],
            3 => ['mode' => ['required', Rule::in(['pickup', 'drop_off'])], 'service_area_id' => ['required', 'uuid'], 'point_id' => ['required_if:mode,drop_off', 'prohibited_if:mode,pickup', 'nullable', 'uuid'], 'address' => ['required_if:mode,pickup', 'prohibited_if:mode,drop_off', 'nullable', 'string', 'max:500'], 'latitude' => ['required_if:mode,pickup', 'prohibited_if:mode,drop_off', 'nullable', 'numeric', 'between:-90,90', 'decimal:0,6'], 'longitude' => ['required_if:mode,pickup', 'prohibited_if:mode,drop_off', 'nullable', 'numeric', 'between:-180,180', 'decimal:0,6'], 'contact' => ['required', 'string', 'max:150'], 'preferred_date' => ['required', 'date_format:Y-m-d']],
            4 => ['privacy_ack' => ['accepted'], 'idempotency_key' => ['required', 'uuid']],
        };
        $allowed = array_merge(array_filter(array_keys($rules), fn ($key) => ! str_contains($key, '.')), ['_token']);
        if (array_diff(array_keys($fields), $allowed)) {
            throw ValidationException::withMessages(['submission' => 'This form contains unsupported fields. Reopen the appropriate step.']);
        }
        $valid = Validator::make($fields, $rules)->validate();
        if ($step === 1) {
            if (array_diff(array_keys($valid['estimated_kg']), $valid['items']) || array_diff($valid['items'], array_keys($valid['estimated_kg']))) {
                throw ValidationException::withMessages(['estimated_kg' => 'Provide a positive estimate in kg for each selected item only.']);
            }
            sort($valid['items']);
            ksort($valid['estimated_kg']);
        }

        return $valid;
    }

    public static function final(#[\SensitiveParameter] array $fields): array
    {
        $groups = [1 => ['items', 'estimated_kg'], 2 => ['mode'], 3 => ['mode', 'service_area_id', 'point_id', 'address', 'latitude', 'longitude', 'contact', 'preferred_date'], 4 => ['privacy_ack', 'idempotency_key']];
        if (array_diff(array_keys($fields), array_unique(array_merge(...array_values($groups))))) {
            throw ValidationException::withMessages(['submission' => 'Unsupported request fields.']);
        }
        $valid = [];
        foreach ($groups as $step => $names) {
            $valid = array_merge($valid, self::validate(array_intersect_key($fields, array_flip($names)), $step));
        }

        return $valid;
    }
}
