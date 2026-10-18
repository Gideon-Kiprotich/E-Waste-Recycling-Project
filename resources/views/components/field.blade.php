@props(['name', 'label', 'type' => 'text', 'value' => '', 'autocomplete' => null, 'required' => true, 'hint' => null, 'maxlength' => null])
@php($inputValue = old($name, $value))
@php($fieldId = str_replace('.', '_', $name))
@php($fieldName = str_contains($name, '.') ? explode('.', $name)[0].'['.explode('.', $name)[1].']' : $name)
<div class="field">
    <label for="{{ $fieldId }}">{{ $label }} @if(!$required)<span class="optional">(optional)</span>@endif</label>
    <input id="{{ $fieldId }}" name="{{ $fieldName }}" type="{{ $type }}" value="{{ $type === 'password' || !is_string($inputValue) ? '' : $inputValue }}"
        @if($autocomplete) autocomplete="{{ $autocomplete }}" @endif
        @if($required) required @endif @if($maxlength) maxlength="{{ $maxlength }}" @endif
        @if($errors->has($name)) aria-invalid="true" @endif
        @if($hint || $errors->has($name)) aria-describedby="{{ $hint ? $fieldId.'-hint ' : '' }}{{ $errors->has($name) ? $fieldId.'-error' : '' }}" @endif>
    @if($hint)<p class="hint" id="{{ $fieldId }}-hint">{{ $hint }}</p>@endif
    @error($name)<p class="field-error" id="{{ $fieldId }}-error">{{ $message }}</p>@enderror
</div>
