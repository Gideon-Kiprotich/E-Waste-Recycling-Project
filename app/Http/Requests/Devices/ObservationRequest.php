<?php

namespace App\Http\Requests\Devices;

use App\Enums\Role;
use App\Models\Item;
use App\Support\Devices\Questions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ObservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! $this->user()?->canSignIn() || $this->user()->role() !== Role::Consumer) {
            return false;
        }
        if ($this->route('item')) {
            $item = Item::with('category')->where('owner_user_id', $this->user()->id)->findOrFail($this->route('item'));
            Gate::authorize('update', $item);
        }

        return true;
    }

    protected function questions(): array
    {
        if ($this->route('item')) {
            $item = Item::with('category')->where('owner_user_id', $this->user()->id)->findOrFail($this->route('item'));

            return Questions::tests($item->category->code, $item->subtype);
        }

        return Questions::tests(is_string($this->input('category')) ? $this->input('category') : '', is_string($this->input('subtype')) ? $this->input('subtype') : null);
    }

    public function rules(): array
    {
        $questions = $this->questions();
        $rules = [
            'idempotency_key' => ['required', 'uuid'],
            'data_status' => ['required', Rule::in(array_keys(Questions::DECLARATIONS))],
            'safety_concern' => ['required', Rule::in(array_keys(Questions::DECLARATIONS))],
            'physical_damage' => ['required', Rule::in(array_keys(Questions::DECLARATIONS))],
            'reason' => ['required', 'string', 'max:1000'],
            'answers' => ['required', 'array:'.implode(',', array_keys($questions)), 'size:'.count($questions)],
        ];
        foreach ($questions as $code => $label) {
            $rules['answers.'.$code] = ['required', Rule::in(array_keys(Questions::RESULTS))];
        }
        foreach (['owner_user_id', 'owner_id', 'subject_id', 'public_code', 'kind', 'status', 'processing_state', 'source', 'observer_id', 'observed_at', 'event', 'events', 'safe_payload', 'custody', 'version', 'role'] as $field) {
            $rules[$field] = ['prohibited'];
        }
        if ($this->route('item')) {
            $rules['expected_version'] = ['required', 'integer', 'min:1'];
            foreach (['category', 'category_id', 'subtype', 'model', 'identifier', 'identifier_ciphertext', 'identifier_hmac', 'photo'] as $field) {
                $rules[$field] = ['prohibited'];
            }
        }

        return $rules;
    }

    public function attributes(): array
    {
        return collect($this->questions())->mapWithKeys(fn ($label, $code) => ['answers.'.$code => $label])->all();
    }
}
