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
        $category = is_string($this->input('category')) ? $this->input('category') : '';
        $subtypes = Questions::subtypes($category);

        return array_merge(parent::rules(), [
            'category' => ['required', 'string', Rule::in(array_keys(Questions::CATEGORIES)), Rule::exists('categories', 'code')->where('supported', true)],
            'subtype' => $subtypes ? ['required', 'string', Rule::in(array_keys($subtypes))] : ['prohibited'],
            'model' => ['nullable', 'string', 'max:150'],
            'identifier_kind' => ['sometimes', 'required', 'string', Rule::in($category === 'standalone_part' ? ['serial'] : ['serial', 'imei'])],
            'identifier' => ['bail', 'nullable', 'string', 'max:100', 'regex:/\A[A-Za-z0-9\s-]+\z/', function (string $attribute, string $value, Closure $fail): void {
                $normalized = Identifiers::normalize($value);
                if ($normalized !== null && $this->input('identifier_kind', 'serial') === 'imei' && ! Identifiers::validImei($normalized)) {
                    $fail('Enter a 15-digit IMEI with a valid check digit, or leave it blank if unknown.');
                }
            }],
            'photo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png', 'mimetypes:image/jpeg,image/png', 'extensions:jpg,jpeg,png', 'max:5120', 'dimensions:max_width=8000,max_height=8000'],
            'category_id' => ['missing'],
            'identifier_ciphertext' => ['missing'],
            'identifier_hmac' => ['missing'],
            'identifier_key_version' => ['missing'],
            'expected_version' => ['missing'],
        ], $category === 'standalone_part' && $this->input('subtype') === 'ict_cooling_fan' ? [
            'answers.fan_scope' => ['required', 'string', Rule::in(['YES'])],
            'answers.fan_electronics' => ['required', 'string', Rule::in(['BASIC', 'UNKNOWN'])],
        ] : []);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), ['category.in' => 'Choose a supported ICT category. Refrigerators, air conditioners, CRT, medical/industrial equipment and loose batteries are excluded.', 'photo.*' => 'Use a valid JPEG or PNG up to 5 MiB. SVG, HTML and executable files are not accepted.', 'answers.fan_scope.in' => Questions::FAN_SCOPE_MESSAGE, 'answers.fan_electronics.in' => Questions::FAN_ELECTRONICS_MESSAGE], $this->input('category') === 'standalone_part' ? ['identifier_kind.in' => 'Use a serial number for a standalone part, or leave it blank if unknown.'] : []);
    }
}
