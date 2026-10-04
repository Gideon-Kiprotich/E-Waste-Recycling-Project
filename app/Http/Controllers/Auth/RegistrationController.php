<?php

namespace App\Http\Controllers\Auth;

use App\Actions\RegisterConsumer;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterRequest;
use App\Support\AuthenticatedSession;
use App\Support\PrivacyNotice;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class RegistrationController extends Controller
{
    public function create(): View
    {
        return view('auth.register', ['noticeVersion' => PrivacyNotice::VERSION]);
    }

    public function store(RegisterRequest $request, RegisterConsumer $registration, AuthenticatedSession $session): RedirectResponse
    {
        $user = $registration->register($request->validated());
        $session->start($request, $user);

        return redirect()->route('consumer.home')->with([
            'status' => 'Your Consumer account has been created. You are signed in.',
            'status_type' => 'success',
        ]);
    }
}
