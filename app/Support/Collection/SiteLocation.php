<?php

namespace App\Support\Collection;

use App\Support\Devices\Identifiers;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SiteLocation
{
    public static function coordinates(): array
    {
        return ['latitude' => ['required', 'numeric', 'between:-90,90', 'regex:/\A-?\d{1,3}(?:\.\d{1,6})?\z/'], 'longitude' => ['required', 'numeric', 'between:-180,180', 'regex:/\A-?\d{1,3}(?:\.\d{1,6})?\z/']];
    }

    public static function token(string $actor, ?string $id, #[\SensitiveParameter] array $fields): string
    {
        Validator::make($fields, self::coordinates() + ['idempotency_key' => ['required', 'uuid'], 'expected_version' => ['nullable', 'integer', 'min:1']])->validate();

        return Identifiers::digest(json_encode([$actor, $id, $fields['idempotency_key'], $fields['expected_version'] ?? null, number_format((float) $fields['latitude'], 6, '.', ''), number_format((float) $fields['longitude'], 6, '.', '')], JSON_THROW_ON_ERROR), 'directory_site');
    }

    public static function validate(string $actor, ?string $id, #[\SensitiveParameter] array $fields): void
    {
        if (empty($fields['site_confirmation']) || ! hash_equals(self::token($actor, $id, $fields), $fields['site_confirmation'])) {
            throw ValidationException::withMessages(['site_confirmation' => 'Confirm the actual site entrance before saving. A changed location needs confirmation again.']);
        }
    }

    public static function remember(?string $id, #[\SensitiveParameter] array $fields): void
    {
        $private = array_intersect_key($fields, array_flip(['latitude', 'longitude', 'site_address', 'site_confirmation', 'idempotency_key', 'expected_version', 'contact', 'reason', 'hours']));
        foreach ($private as $key => $value) {
            if (! is_scalar($value) || strlen((string) $value) > ($key === 'reason' ? 1000 : 500)) {
                unset($private[$key]);
            }
        }
        session()->put('directory_location', Crypt::encryptString(json_encode(['record' => $id, 'fields' => $private, 'expires' => time() + 1800], JSON_THROW_ON_ERROR)));
    }

    public static function recalled(?string $id): array
    {
        if (! session()->has('directory_location')) {
            return [];
        }
        $data = json_decode(Crypt::decryptString(session('directory_location')), true, flags: JSON_THROW_ON_ERROR);

        return $data['record'] === $id && $data['expires'] >= time() ? $data['fields'] : [];
    }
}
