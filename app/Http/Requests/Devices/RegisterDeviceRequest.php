<?php

namespace App\Http\Requests\Devices;

use App\Support\Devices\Identifiers;
use App\Support\Devices\Questions;
use Closure;
use Illuminate\Validation\Rule;

class RegisterDeviceRequest extends ObservationRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'category' => ['required', 'string', Rule::in(array_keys(Questions::CATEGORIES)), Rule::exists('categories', 'code')->where('supported', true)],
            'subtype' => $this->input('category') === 'peripheral' ? ['required', Rule::in(['router', 'keyboard'])] : ['prohibited'],
            'model' => ['nullable', 'string', 'max:150'],
            'identifier_kind' => ['sometimes', 'required', 'string', Rule::in(['serial', 'imei'])],
            'identifier' => ['bail', 'nullable', 'string', 'max:100', 'regex:/\A[A-Za-z0-9\s-]+\z/', function (string $attribute, string $value, Closure $fail): void {
                $normalized = Identifiers::normalize($value);
                if ($normalized !== null && $this->input('identifier_kind', 'serial') === 'imei' && ! Identifiers::validImei($normalized)) {
                    $fail('Enter a 15-digit IMEI with a valid check digit, or leave it blank if unknown.');
                }
            }],
            'photo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png', 'mimetypes:image/jpeg,image/png', 'extensions:jpg,jpeg,png', 'max:5120', 'dimensions:max_width=8000,max_height=8000'],
            'category_id' => ['prohibited'],
            'identifier_ciphertext' => ['prohibited'],
            'identifier_hmac' => ['prohibited'],
            'expected_version' => ['prohibited'],
        ]);
    }

    public function messages(): array
    {
        return ['category.in' => 'Choose a supported ICT category. Refrigerators, air conditioners, CRT, medical/industrial equipment and loose batteries are excluded.', 'photo.*' => 'Use a valid JPEG or PNG up to 5 MiB. SVG, HTML and executable files are not accepted.'];
    }
}
