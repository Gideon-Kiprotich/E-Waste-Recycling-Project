<?php

namespace App\Http\Requests;

use App\Support\PrivacyNotice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'display_name' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:254', 'unique:users,email'],
            'password' => ['required', 'string', 'max:128', 'confirmed', Password::min(12)],
            'notice_acknowledged' => ['accepted'],
            'notice_version' => ['required', Rule::in([PrivacyNotice::VERSION])],
            'role' => ['prohibited'],
            'status' => ['prohibited'],
            'memberships' => ['prohibited'],
            'session_version' => ['prohibited'],
        ];
    }
}
