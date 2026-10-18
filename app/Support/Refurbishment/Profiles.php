<?php

namespace App\Support\Refurbishment;

use App\Support\Assessments\Schemas;

class Profiles
{
    public const VERSION = 'REUSE-TEST-1.0';

    public static function catalog(): array
    {
        return json_decode(file_get_contents(database_path('data/reuse-tests-1.0.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function questions(object $item): array
    {
        return self::catalog()['profiles'][Schemas::key($item->category, $item->subtype)] ?? [];
    }

    public static function result(array $answers): string
    {
        return in_array('FAIL', $answers, true) ? 'failed' : (in_array('UNKNOWN', $answers, true) ? 'incomplete' : 'passed');
    }
}
