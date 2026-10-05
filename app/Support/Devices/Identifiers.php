<?php

namespace App\Support\Devices;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;

class Identifiers
{
    public const KEY_VERSION = 'app-key-context-1';

    public const UNIQUE_INDEX = 'items_identifier_hmac_unique';

    public const DUPLICATE_MESSAGE = 'This serial/IMEI is already registered. Please recheck the number entered.';

    public static function isDuplicateConflict(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23505'
            && str_contains($exception->errorInfo[2] ?? '', '"'.self::UNIQUE_INDEX.'"');
    }

    public static function validImei(string $identifier): bool
    {
        if (! preg_match('/\A[0-9]{15}\z/', $identifier)) {
            return false;
        }

        // The printed IMEI check digit follows 3GPP TS 23.003 Annex B (Luhn).
        $sum = 0;
        for ($position = 0; $position < 15; $position++) {
            $digit = (int) $identifier[$position];
            if ($position % 2 === 1) {
                $digit *= 2;
                $digit = $digit > 9 ? $digit - 9 : $digit;
            }
            $sum += $digit;
        }

        return $sum % 10 === 0;
    }

    public static function digest(string $value, string $context = 'identifier'): string
    {
        return hash_hmac('sha256', $context.'\0'.$value, Crypt::getKey());
    }

    public static function normalize(?string $identifier): ?string
    {
        if ($identifier === null || trim($identifier) === '') {
            return null;
        }

        $normalized = strtoupper(preg_replace('/[\s-]+/u', '', trim($identifier)));

        return $normalized === '' ? null : $normalized;
    }
}
