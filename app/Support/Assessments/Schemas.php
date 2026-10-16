<?php

namespace App\Support\Assessments;

class Schemas
{
    public static function catalog(): array
    {
        return json_decode(file_get_contents(database_path('data/inspection-rules-1.0.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function key(string $category, ?string $subtype): string
    {
        return in_array($category, ['peripheral', 'standalone_part'], true) ? ($subtype ?? '') : $category;
    }

    public static function definition(string $category, ?string $subtype): array
    {
        $set = self::catalog()[$category === 'standalone_part' ? 'PARTS-ASSESS-1.0' : 'ASSESS-1.0'];
        $set['questions'] = $set['schemas'][self::key($category, $subtype)] ?? [];

        return $set;
    }

    public static function checksum(array $set): string
    {
        return hash('sha256', json_encode($set, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
