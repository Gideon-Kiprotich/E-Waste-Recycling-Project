<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['role', 'active'])]
class Membership extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['role' => Role::class, 'active' => 'boolean'];
    }
}
