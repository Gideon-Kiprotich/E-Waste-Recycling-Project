<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['display_name', 'email', 'password_hash'])]
#[Hidden(['password_hash', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable;

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function membership(): HasOne
    {
        return $this->hasOne(Membership::class);
    }

    public function noticeAcknowledgments(): HasMany
    {
        return $this->hasMany(NoticeAcknowledgment::class);
    }

    public function role(): ?Role
    {
        return $this->membership?->active ? $this->membership->role : null;
    }

    public function canSignIn(): bool
    {
        return $this->status === 'active' && $this->role() !== null;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password_hash' => 'hashed',
            'version' => 'integer',
            'session_version' => 'integer',
        ];
    }
}
