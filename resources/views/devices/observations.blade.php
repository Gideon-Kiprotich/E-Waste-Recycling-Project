@php($values = isset($current) ? $current->answers : [])
@php($observationCategory = isset($item) ? $item->category->code : $category)
@php($observationSubtype = isset($item) ? $item->subtype : $subtype)
@php($isPart = $observationCategory === 'standalone_part')
@php($questionVersion = isset($current) ? $current->question_version : \App\Support\Devices\Questions::version($observationCategory))
<fieldset><legend>Consumer self-report</legend>
    <p>{{ $isPart ? 'Record only what you already know. Unknown is a useful answer. Do not power, dismantle or inspect inside a part to complete this form.' : 'Record what you already know. Choose Unknown for untested functions. Do not open or test an unsafe device.' }} These answers are not technician inspection or an approved processing recommendation.</p>
    @if($isPart && $observationSubtype === 'ict_cooling_fan')<p>One computer/ICT cooling fan or blower, up to 24 V DC and 140 mm across its housing, excluding free leads. Mains/industrial fans and separate control modules are excluded. If the type is unknown, do not test or dismantle it.</p><p>Ordinary motor-driver, tachometer and PWM circuitry counts as basic. Unknown electronics on an otherwise eligible fan needs review. Newly learned uncertainty can be recorded in a later revision.</p>@endif
    <div class="form-grid">
        @foreach($tests as $code => $label)<x-select-field :name="'answers.'.$code" :label="$label" :options="\App\Support\Devices\Questions::options($code, $questionVersion)" :value="$values['answers'][$code] ?? ''" />@endforeach
        <x-select-field name="physical_damage" label="Visible physical damage" :options="\App\Support\Devices\Questions::DECLARATIONS" :value="$values['physical_damage'] ?? ''" />
        @unless($isPart)<x-select-field name="data_status" label="Could this device hold data?" :options="\App\Support\Devices\Questions::DECLARATIONS" :value="$values['data_status'] ?? ''" hint="Includes internal storage or saved configuration. Unknown does not mean data-free." />@endunless
        <x-select-field name="safety_concern" label="Any safety concern?" :options="\App\Support\Devices\Questions::DECLARATIONS" :value="$values['safety_concern'] ?? ''" hint="Examples: exposed wiring or sharp damage. Unknown is allowed." />
    </div>
    @if($isPart)
        @if(in_array($observationSubtype, ['hdd','ssd'], true))<p><strong>Data-bearing: Yes — storage medium.</strong> This remains a data-bearing item even if it is empty, broken or you previously erased it. Registration does not verify sanitization or approve reuse.</p>
        @else<p><strong>Data-bearing: {{ ($values['data_status'] ?? 'UNKNOWN') === 'NO' ? 'No — consumer self-report' : 'Unknown — review needed' }}.</strong> This is derived from recorded scope/electronics answers, not a verified absence of data. Electronics/data status may need review; registration does not approve collection or treatment.</p>@endif
    @endif
    <div class="field"><label for="reason">Observation notes / reason</label><textarea id="reason" name="reason" required maxlength="1000" rows="4" aria-describedby="reason-hint{{ $errors->has('reason') ? ' reason-error' : '' }}" @if($errors->has('reason')) aria-invalid="true" @endif>{{ is_string(old('reason', $values['reason'] ?? '')) ? old('reason', $values['reason'] ?? '') : '' }}</textarea>
        <p class="hint" id="reason-hint">Explain the answers or why they are unknown. Do not include contacts, addresses, passwords, serials or device contents.</p>@error('reason')<p class="field-error" id="reason-error">{{ $message }}</p>@enderror
    </div>
</fieldset>
