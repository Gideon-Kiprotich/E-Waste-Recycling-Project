<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ItemObservation extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['answers' => 'array', 'observed_at' => 'immutable_datetime', 'revision' => 'integer'];
    }
}
