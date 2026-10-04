<?php

namespace App\Http\Controllers\Auth;

use App\Actions\ResetAccountPassword;
use App\Http\Controllers\Controller;
use App\Http\Requests\RecoveryRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Models\User;
use App\Support\AuthenticatedSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class PasswordRecoveryController extends Controller
{
    public const REQUEST_MESSAGE = 'If an eligible account matches that email, a password reset link will be sent. Please check your inbox.';

    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(RecoveryRequest $request): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request): void {
                $account = User::where('email', $request->validated('email'))->lockForUpdate()->first();
                if ($account?->canSignIn()) {
                    Password::sendResetLink(['email' => $account->email]);
                }
            });
        } catch (TransportExceptionInterface) {
            Log::warning('Recovery mail delivery unavailable');
        }

        return back()->with('status', self::REQUEST_MESSAGE);
    }

    public function edit(Request $request, string $token): View
    {
        return view('auth.reset-password', ['token' => $token, 'email' => $request->query('email', '')]);
    }

    public function update(ResetPasswordRequest $request, ResetAccountPassword $reset, AuthenticatedSession $session): RedirectResponse
    {
        $status = $reset->reset($request->validated());
        if ($status !== Password::PASSWORD_RESET) {
            return back()->withErrors(['email' => 'This reset link is invalid or expired. Request a new link.'])
                ->withInput($request->only('email'));
        }

        $session->end($request);

        return redirect()->route('login')->with('status', 'Password updated. Sign in with your new password.');
    }
}
