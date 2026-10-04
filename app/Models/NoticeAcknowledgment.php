<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['notice_version', 'acknowledged_at'])]
class NoticeAcknowledgment extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['acknowledged_at' => 'immutable_datetime'];
    }
}
