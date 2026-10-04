<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends RecoveryRequest
{
    public function rules(): array
    {
        return [...parent::rules(),
            'token' => ['required', 'string', 'max:256'],
            'password' => ['required', 'string', 'max:128', 'confirmed', Password::min(12)],
        ];
    }
}
