<?php

namespace App\Enums;

enum Role: string
{
    case Consumer = 'consumer';
    case Collector = 'collector';
    case Processor = 'processor';
    case Administrator = 'administrator';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
