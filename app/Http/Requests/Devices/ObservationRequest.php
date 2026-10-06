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
        [$category, $subtype] = $this->facts();

        return Questions::tests($category, $subtype);
    }

    protected function facts(): array
    {
        if ($this->route('item')) {
            $item = Item::with('category')->where('owner_user_id', $this->user()->id)->findOrFail($this->route('item'));

            return [$item->category->code, $item->subtype];
        }

        return [is_string($this->input('category')) ? $this->input('category') : '', is_string($this->input('subtype')) ? $this->input('subtype') : null];
    }

    public function rules(): array
    {
        $questions = $this->questions();
        [$category] = $this->facts();
        $rules = [
            'idempotency_key' => ['required', 'uuid'],
            'data_status' => $category === 'standalone_part' ? ['missing'] : ['required', Rule::in(array_keys(Questions::DECLARATIONS))],
            'safety_concern' => ['required', Rule::in(array_keys(Questions::DECLARATIONS))],
            'physical_damage' => ['required', Rule::in(array_keys(Questions::DECLARATIONS))],
            'reason' => ['required', 'string', 'max:1000'],
            'answers' => ['required', 'array:'.implode(',', array_keys($questions)), 'size:'.count($questions)],
        ];
        foreach ($questions as $code => $label) {
            $rules['answers.'.$code] = ['required', 'string', Rule::in(array_keys(Questions::options($code, Questions::version($category))))];
        }
        foreach (['owner_user_id', 'owner_id', 'subject_id', 'public_code', 'kind', 'status', 'processing_state', 'source', 'observer_id', 'observed_at', 'event', 'events', 'safe_payload', 'custody', 'version', 'role'] as $field) {
            $rules[$field] = ['missing'];
        }
        foreach (['intake_class', 'origin_kind', 'parent', 'parent_id', 'parent_item_id', 'parent_subject_id', 'parent_links', 'removed_from', 'detachment_event_id', 'batch_id', 'storage_id', 'storage_subject_id', 'storage_assets', 'embedded', 'quantity'] as $field) {
            $rules[$field] = ['missing'];
        }
        if ($this->route('item')) {
            $rules['expected_version'] = ['required', 'integer', 'min:1'];
            foreach (['category', 'category_id', 'subtype', 'model', 'identifier', 'identifier_kind', 'identifier_ciphertext', 'identifier_hmac', 'identifier_key_version', 'photo'] as $field) {
                $rules[$field] = ['missing'];
            }
        }

        return $rules;
    }

    public function withValidator($validator): void
    {
        [$category] = $this->facts();
        if ($category !== 'standalone_part') {
            return;
        }
        $allowed = $this->route('item')
            ? ['_token', '_method', 'idempotency_key', 'expected_version', 'answers', 'physical_damage', 'safety_concern', 'reason']
            : ['_token', 'category', 'subtype', 'model', 'identifier_kind', 'identifier', 'photo', 'idempotency_key', 'answers', 'physical_damage', 'safety_concern', 'reason'];
        $validator->after(function ($validator) use ($allowed): void {
            foreach (array_diff(array_keys($this->all()), $allowed) as $field) {
                if (! $validator->errors()->has($field)) {
                    $validator->errors()->add($field, 'This field is not accepted for standalone registration.');
                }
            }
        });
    }

    public function messages(): array
    {
        return ['*.missing' => 'This field is not accepted for standalone registration.'];
    }

    public function attributes(): array
    {
        return collect($this->questions())->mapWithKeys(fn ($label, $code) => ['answers.'.$code => $label])->all();
    }
}
