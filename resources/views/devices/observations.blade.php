@php($values = isset($current) ? $current->answers : [])
<fieldset><legend>Consumer self-report</legend>
    <p>Record what you already know. Choose Unknown for untested functions. These answers are not technician inspection or an approved processing recommendation. Do not open or test an unsafe device.</p>
    <div class="form-grid">
        @foreach($tests as $code => $label)<x-select-field :name="'answers.'.$code" :label="$label" :options="\App\Support\Devices\Questions::RESULTS" :value="$values['answers'][$code] ?? ''" />@endforeach
        <x-select-field name="physical_damage" label="Visible physical damage" :options="\App\Support\Devices\Questions::DECLARATIONS" :value="$values['physical_damage'] ?? ''" />
        <x-select-field name="data_status" label="Could this device hold data?" :options="\App\Support\Devices\Questions::DECLARATIONS" :value="$values['data_status'] ?? ''" hint="Includes internal storage or saved configuration. Unknown does not mean data-free." />
        <x-select-field name="safety_concern" label="Any safety concern?" :options="\App\Support\Devices\Questions::DECLARATIONS" :value="$values['safety_concern'] ?? ''" hint="Examples: swollen/leaking embedded battery, exposed wiring or sharp damage. Unknown is allowed." />
    </div>
    <div class="field"><label for="reason">Observation notes / reason</label><textarea id="reason" name="reason" required maxlength="1000" rows="4" aria-describedby="reason-hint{{ $errors->has('reason') ? ' reason-error' : '' }}" @if($errors->has('reason')) aria-invalid="true" @endif>{{ is_string(old('reason', $values['reason'] ?? '')) ? old('reason', $values['reason'] ?? '') : '' }}</textarea>
        <p class="hint" id="reason-hint">Explain the answers or why they are unknown. Do not include contacts, addresses, passwords, serials or device contents.</p>@error('reason')<p class="field-error" id="reason-error">{{ $message }}</p>@enderror
    </div>
</fieldset>
