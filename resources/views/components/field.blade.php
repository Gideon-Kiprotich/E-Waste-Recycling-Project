@props(['name', 'label', 'type' => 'text', 'value' => '', 'autocomplete' => null, 'required' => true, 'hint' => null, 'maxlength' => null])
@php($inputValue = old($name, $value))
<div class="field">
    <label for="{{ $name }}">{{ $label }} @if(!$required)<span class="optional">(optional)</span>@endif</label>
    <input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}" value="{{ $type === 'password' || !is_string($inputValue) ? '' : $inputValue }}"
        @if($autocomplete) autocomplete="{{ $autocomplete }}" @endif
        @if($required) required @endif @if($maxlength) maxlength="{{ $maxlength }}" @endif
        @if($errors->has($name)) aria-invalid="true" @endif
        @if($hint || $errors->has($name)) aria-describedby="{{ $hint ? $name.'-hint ' : '' }}{{ $errors->has($name) ? $name.'-error' : '' }}" @endif>
    @if($hint)<p class="hint" id="{{ $name }}-hint">{{ $hint }}</p>@endif
    @error($name)<p class="field-error" id="{{ $name }}-error">{{ $message }}</p>@enderror
</div>
