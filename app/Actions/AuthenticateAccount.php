<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthenticateAccount
{
    public function authenticate(#[\SensitiveParameter] array $credentials, string $ip): User
    {
        $key = 'login:'.hash('sha256', $credentials['email'].'|'.$ip);
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many sign-in attempts. Try again in '.RateLimiter::availableIn($key).' seconds.',
            ]);
        }

        $user = DB::transaction(function () use ($credentials): ?User {
            $account = User::where('email', $credentials['email'])->lockForUpdate()->first();
            if (! Auth::attempt($credentials)) {
                return null;
            }
            if (! $account?->canSignIn()) {
                Auth::logout();

                return null;
            }

            return $account;
        });

        if (! $user) {
            RateLimiter::hit($key, 900);
            Log::notice('Authentication denied');
            throw ValidationException::withMessages(['email' => 'Unable to sign in with these credentials.']);
        }

        RateLimiter::clear($key);
        Log::info('Authentication accepted', ['user_id' => $user->id]);

        return $user;
    }
}
