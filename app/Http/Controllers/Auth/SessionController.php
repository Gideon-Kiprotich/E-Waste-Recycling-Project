<?php

namespace App\Http\Controllers\Auth;

use App\Actions\AuthenticateAccount;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Support\AuthenticatedSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class SessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request, AuthenticateAccount $authentication, AuthenticatedSession $session): RedirectResponse
    {
        $user = $authentication->authenticate($request->validated(), $request->ip());
        $session->start($request, $user);

        return redirect()->route($user->role()->value.'.home');
    }

    public function destroy(Request $request, AuthenticatedSession $session): RedirectResponse
    {
        Log::info('Authentication logout', ['user_id' => $request->user()?->id]);
        $session->end($request);

        return redirect()->route('login')->with('status', 'You have signed out.');
    }
}
