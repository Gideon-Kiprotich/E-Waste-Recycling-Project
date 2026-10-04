<?php

namespace App\Support\Devices;

use Illuminate\Support\Facades\Crypt;

class Identifiers
{
    public const KEY_VERSION = 'app-key-context-1';

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
