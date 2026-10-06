<?php

namespace App\Support\Collection;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PickupPoint
{
    public static function key(#[\SensitiveParameter] array $fields): ?array
    {
        foreach (['latitude' => 90, 'longitude' => 180] as $name => $limit) {
            $value = $fields[$name] ?? null;
            if (! is_scalar($value) || ! preg_match('/\A[+-]?(?:[0-9]+(?:\.[0-9]{0,6})?|\.[0-9]{1,6})\z/', (string) $value) || ! is_finite((float) $value) || abs((float) $value) > $limit) {
                return null;
            }
        }
        $area = $fields['service_area_id'] ?? null;
        if (! is_string($area) || ! Str::isUuid($area)) {
            return null;
        }

        return ['latitude' => number_format((float) $fields['latitude'], 6, '.', ''), 'longitude' => number_format((float) $fields['longitude'], 6, '.', ''), 'service_area_id' => $area];
    }

    public static function confirmed(#[\SensitiveParameter] array $draft, #[\SensitiveParameter] ?array $fields = null): bool
    {
        $point = self::key($fields ?? $draft['fields']);

        return $point !== null && ($draft['pickup_confirmation'] ?? null) === $point;
    }

    public static function requireConfirmation(#[\SensitiveParameter] array $draft, #[\SensitiveParameter] ?array $fields = null): void
    {
        if (! self::confirmed($draft, $fields)) {
            throw ValidationException::withMessages(['pickup_point' => 'Confirm the selected pickup point before continuing. A changed point or service area needs confirmation again.']);
        }
    }
}
