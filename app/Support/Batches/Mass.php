<?php

namespace App\Support\Batches;

use Illuminate\Validation\ValidationException;

class Mass
{
    public static function milli(mixed $value, string $field): int
    {
        if (! is_string($value) && ! is_int($value)) {
            throw ValidationException::withMessages([$field => 'Enter kilograms with at most three decimal places.']);
        }
        if (! preg_match('/\A(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,3})?\z/', (string) $value)) {
            throw ValidationException::withMessages([$field => 'Use non-negative kg, at most nine whole digits and three decimals; no exponents or rounding.']);
        }
        [$whole, $fraction] = array_pad(explode('.', (string) $value), 2, '');

        return (int) $whole * 1000 + (int) str_pad($fraction, 3, '0');
    }

    public static function decimal(int $milli): string
    {
        return intdiv($milli, 1000).'.'.str_pad((string) ($milli % 1000), 3, '0', STR_PAD_LEFT);
    }

    public static function measurement(array $fields): array
    {
        $mode = $fields['measure_mode'];
        if ($mode === 'unknown') {
            foreach (['net_kg', 'gross_kg', 'tare_kg', 'measurement_source'] as $f) {
                if (filled($fields[$f] ?? null)) {
                    throw ValidationException::withMessages([$f => 'Unknown mass cannot contain a measurement. Select the actual measurement mode.']);
                }
            }

            return ['measure_mode' => null, 'net_kg' => null, 'gross_kg' => null, 'tare_kg' => null];
        }
        if (blank($fields['measurement_source'] ?? null)) {
            throw ValidationException::withMessages(['measurement_source' => 'Record the actual instrument/source and measurement provenance.']);
        }
        if ($mode === 'net') {
            if (filled($fields['gross_kg'] ?? null) || filled($fields['tare_kg'] ?? null)) {
                throw ValidationException::withMessages(['gross_kg' => 'Net-only measurement cannot also contain gross/tare values.']);
            }
            $net = self::milli($fields['net_kg'] ?? null, 'net_kg');
            $gross = $tare = null;
        } else {
            $gross = self::milli($fields['gross_kg'] ?? null, 'gross_kg');
            $tare = self::milli($fields['tare_kg'] ?? null, 'tare_kg');
            $net = $gross - $tare;
            if (filled($fields['net_kg'] ?? null) && self::milli($fields['net_kg'], 'net_kg') !== $net) {
                throw ValidationException::withMessages(['net_kg' => 'Net kg must equal gross minus tare exactly.']);
            }
        }
        if ($net <= 0) {
            throw ValidationException::withMessages(['net_kg' => 'Measured net kg must be positive; gross must exceed tare. Unknown mass must remain pending.']);
        }

        return ['measure_mode' => $mode, 'net_kg' => self::decimal($net), 'gross_kg' => $gross === null ? null : self::decimal($gross), 'tare_kg' => $tare === null ? null : self::decimal($tare)];
    }
}
