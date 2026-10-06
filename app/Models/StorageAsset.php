<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorageAsset extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'subject_id';

    protected $keyType = 'string';

    protected $guarded = ['*'];
}
