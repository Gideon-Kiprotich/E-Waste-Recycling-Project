<?php

namespace App\Actions;

use App\Enums\Role;
use App\Models\User;
use App\Support\PrivacyNotice;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegisterConsumer
{
    public function register(#[\SensitiveParameter] array $fields): User
    {
        try {
            return DB::transaction(function () use ($fields): User {
                $user = User::create([
                    'display_name' => $fields['display_name'] ?? null,
                    'email' => $fields['email'],
                    'password_hash' => $fields['password'],
                ]);
                $user->membership()->create(['role' => Role::Consumer, 'active' => true]);
                $user->noticeAcknowledgments()->create([
                    'notice_version' => PrivacyNotice::VERSION,
                    'acknowledged_at' => now(),
                ]);

                return $user->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'This email is already registered.']);
        }
    }
}
