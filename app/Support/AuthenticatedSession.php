<?php

namespace App\Support;

use App\Models\User;
use App\Support\Reporting\ReportSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthenticatedSession
{
    public function start(Request $request, User $user): void
    {
        ReportSession::invalidate($user);
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('auth_session_version', $user->session_version);
    }

    public function end(Request $request): void
    {
        if ($user = $request->user()) {
            ReportSession::invalidate($user);
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
