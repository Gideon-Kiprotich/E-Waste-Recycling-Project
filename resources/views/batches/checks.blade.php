@foreach($checks as $key=>$label)
<div class="acknowledgment"><input id="checks_{{ $key }}" name="checks[{{ $key }}]" type="checkbox" value="YES" required @if($errors->has('checks.'.$key)) aria-invalid="true" aria-describedby="checks_{{ $key }}-error" @endif><label for="checks_{{ $key }}">{{ $label }}</label></div>
@error('checks.'.$key)<p class="field-error" id="checks_{{ $key }}-error">{{ $message }}</p>@enderror
@endforeach
