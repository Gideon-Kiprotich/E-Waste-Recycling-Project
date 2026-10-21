@props(['name', 'label', 'options', 'value' => '', 'hint' => null, 'required' => true, 'id' => null])
@php($fieldId = $id ?? str_replace('.', '_', $name))
@php($segments = explode('.', $name))
@php($fieldName = array_shift($segments).implode('', array_map(fn ($segment) => '['.$segment.']', $segments)))
@php($selected = old($name, $value))
<div class="field">
    <label for="{{ $fieldId }}">{{ $label }}</label>
    <select id="{{ $fieldId }}" name="{{ $fieldName }}" @if($required) required @endif @if($errors->has($name)) aria-invalid="true" @endif
        @if($hint || $errors->has($name)) aria-describedby="{{ $hint ? $fieldId.'-hint ' : '' }}{{ $errors->has($name) ? $fieldId.'-error' : '' }}" @endif>
        <option value="">Choose an answer</option>
        @foreach($options as $code => $text)<option value="{{ $code }}" @selected(is_string($selected) && $selected === (string) $code)>{{ $text }}</option>@endforeach
    </select>
    @if($hint)<p id="{{ $fieldId }}-hint" class="hint">{{ $hint }}</p>@endif
    @error($name)<p id="{{ $fieldId }}-error" class="field-error">{{ $message }}</p>@enderror
</div>
