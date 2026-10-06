<?php

namespace App\Support\Collection;

use App\Support\Devices\Questions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DirectoryInput
{
    public static function capabilities(): array
    {
        $options = [];
        foreach (Questions::CATEGORIES as $code => $label) {
            $subtypes = match ($code) {
                'peripheral' => ['router', 'keyboard'], 'standalone_part' => ['hdd', 'ssd', 'ict_cooling_fan'], default => ['']
            };
            foreach ($subtypes as $subtype) {
                $options[$code.':'.$subtype] = ($subtype !== '' ? str_replace('_', ' ', strtoupper($subtype)).' · ' : '').$label;
            }
        }

        return $options;
    }

    public static function validate(string $kind, bool $editing, #[\SensitiveParameter] array $fields, ?UploadedFile $evidence): array
    {
        $rules = ['name' => ['required', 'string', 'max:160'], 'kind' => ['required', Rule::in($kind === 'organization' ? ['collector', 'processor', 'both'] : ['collection_point', 'facility', 'depot'])],
            'review_status' => ['required', Rule::in(['pending', 'approved', 'suspended', 'rejected'])], 'contact' => ['required', 'string', 'max:500'], 'reason' => ['required', 'string', 'max:1000'], 'idempotency_key' => ['required', 'uuid']];
        if ($editing) {
            $rules['expected_version'] = ['required', 'integer', 'min:1'];
        } else {
            $rules['synthetic'] = ['required', Rule::in(['0', '1'])];
        }
        if ($kind === 'place') {
            if (! $editing) {
                $rules['organization_id'] = ['required', 'uuid'];
            }
            $rules += ['latitude' => ['required', 'numeric', 'between:-90,90', 'regex:/\A-?\d{1,3}(?:\.\d{1,6})?\z/'], 'longitude' => ['required', 'numeric', 'between:-180,180', 'regex:/\A-?\d{1,3}(?:\.\d{1,6})?\z/'], 'hours' => ['required', 'string', 'max:500'], 'active' => ['required', Rule::in(['0', '1'])], 'capabilities' => ['required', 'array', 'min:1', 'max:10'], 'capabilities.*' => ['required', 'string', 'distinct', Rule::in(array_keys(self::capabilities()))]];
        }
        if (array_diff(array_keys($fields), array_filter(array_keys($rules), fn ($key) => ! str_contains($key, '.')))) {
            throw ValidationException::withMessages(['submission' => 'Unsupported directory fields. Reopen the form.']);
        }
        Validator::make(['evidence' => $evidence], ['evidence' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120']])->validate();
        $values = Validator::make($fields, $rules)->validate();
        if (isset($values['capabilities'])) {
            sort($values['capabilities']);
        }

        return $values;
    }

    public static function safe(array $fields): array
    {
        $safe = [];
        foreach (['name', 'kind', 'review_status', 'synthetic', 'active', 'idempotency_key', 'expected_version', 'organization_id'] as $key) {
            if (isset($fields[$key]) && is_scalar($fields[$key]) && strlen((string) $fields[$key]) <= 160) {
                $safe[$key] = (string) $fields[$key];
            }
        }
        if (isset($fields['capabilities']) && is_array($fields['capabilities'])) {
            $safe['capabilities'] = array_values(array_intersect(array_filter($fields['capabilities'], 'is_string'), array_keys(self::capabilities())));
        }

        return $safe;
    }
}
