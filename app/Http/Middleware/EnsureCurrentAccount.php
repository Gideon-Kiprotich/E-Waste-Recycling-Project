<?php

namespace App\Http\Middleware;

use App\Support\AuthenticatedSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnsureCurrentAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user()?->fresh(['membership']);
        if (! $user?->canSignIn()
            || $request->session()->get('auth_session_version') !== $user->session_version) {
            Log::notice('Authentication session rejected', ['user_id' => $user?->id]);
            app(AuthenticatedSession::class)->end($request);

            return redirect()->route('login')->withErrors(['email' => 'Please sign in again. Account access may have changed.']);
        }

        Auth::setUser($user);

        return $next($request);
    }
}
