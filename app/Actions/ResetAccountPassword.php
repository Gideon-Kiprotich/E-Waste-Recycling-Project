<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class ResetAccountPassword
{
    public function reset(#[\SensitiveParameter] array $credentials): string
    {
        return DB::transaction(function () use ($credentials): string {
            // Login, recovery issuance and reset share this lock so tokens are consumed once.
            $user = User::where('email', $credentials['email'])->lockForUpdate()->first();
            if (! $user?->canSignIn()) {
                return Password::INVALID_TOKEN;
            }

            return Password::reset($credentials, function (User $account, #[\SensitiveParameter] string $password): void {
                $account->forceFill([
                    'password_hash' => $password,
                    'remember_token' => Str::random(60),
                    'session_version' => $account->session_version + 1,
                    'version' => $account->version + 1,
                ])->save();
                event(new PasswordReset($account));
                Log::notice('Authentication password reset', ['user_id' => $account->id]);
            });
        });
    }
}
