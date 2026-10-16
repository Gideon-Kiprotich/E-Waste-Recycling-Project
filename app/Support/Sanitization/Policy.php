<?php

namespace App\Support\Sanitization;

class Policy
{
    public const ID = 'a3d71835-fce3-4ad7-ae2f-cda4507b1001';

    public static function definition(): array
    {
        return json_decode(file_get_contents(database_path('data/media-assurance-1.0.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function checksum(): string
    {
        return hash('sha256', json_encode(self::definition(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public static function checks(string $kind): array
    {
        return self::definition()[$kind] ?? [];
    }
}
