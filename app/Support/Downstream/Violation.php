<?php

namespace App\Support\Downstream;

use Illuminate\Validation\ValidationException;
use Throwable;

class Violation
{
    public static function raise(Throwable $e): never
    {
        if ($e instanceof \PDOException && in_array($e->errorInfo[0] ?? null, ['P1300', 'P1600'], true) && preg_match('/ERROR:\s*([^\n]+)/', $e->errorInfo[2] ?? '', $match)) {
            throw ValidationException::withMessages(['submission' => $match[1]]);
        }
        throw $e;
    }
}
