@extends('layouts.devices')
@php($fieldIds = array_merge(array_combine(['category','subtype','model','identifier','photo','data_status','safety_concern','physical_damage','reason'], ['category','subtype','model','identifier','photo','data_status','safety_concern','physical_damage','reason']), collect($tests)->mapWithKeys(fn ($label, $code) => ['answers.'.$code => 'answers_'.$code])->all()))
@section('title', 'Register a device')
@section('intro', 'Register one physical item. Your private serial and photo are optional.')
@section('content')
    <div class="workspace">
        <section class="card">
            <div class="card-heading"><h2>Device facts and self-report</h2></div>
            <form class="account-form category-picker" method="GET" action="{{ route('items.create') }}">
                <x-select-field name="category" label="Supported category" :options="$categories->pluck('label','code')->all()" :value="$category" />
                <x-select-field name="subtype" label="Peripheral subtype (required for peripherals only)" :options="['router'=>'Router','keyboard'=>'Keyboard']" :value="$subtype" :required="false" />
                <button class="secondary" type="submit">Load category questions</button><p class="hint">Choose the category first. Changing it reloads the form; unsaved answers are discarded.</p>
            </form>
            @if(count($tests))
                <form method="POST" action="{{ route('items.store') }}" enctype="multipart/form-data" class="account-form">
                    @csrf<input type="hidden" name="idempotency_key" value="{{ $commandKey }}"><input type="hidden" name="category" value="{{ $category }}">@if($subtype)<input type="hidden" name="subtype" value="{{ $subtype }}">@endif
                    <p><strong>{{ \App\Support\Devices\Questions::CATEGORIES[$category] }}{{ $subtype ? ' · '.$subtype : '' }}</strong></p>
                    <x-field name="model" label="Make / model" :required="false" :maxlength="150" hint="Leave blank if unknown." />
                    <x-field name="identifier" label="Serial / IMEI" :required="false" :maxlength="100" hint="Leave blank if unknown, missing or unreadable. It is private and cannot be changed through ordinary edits." />
                    @include('devices.observations')
                    <div class="field"><label for="photo">Device photo <span class="optional">(optional)</span></label><input id="photo" name="photo" type="file" accept="image/jpeg,image/png" aria-describedby="photo-hint{{ $errors->has('photo') ? ' photo-error' : '' }}" @if($errors->has('photo')) aria-invalid="true" @endif>
                        <p class="hint" id="photo-hint">JPEG/PNG, maximum 5 MiB and 16 megapixels. Re-encoded privately to remove metadata. Do not include people, private screens or device contents. Select the file again after a failed submission.</p>@error('photo')<p class="field-error" id="photo-error">{{ $message }}</p>@enderror
                    </div>
                    <button class="primary" type="submit">Register device and create label</button>
                </form>
            @else<p class="notice-content">Select Router or Keyboard, then load the category questions.</p>@endif
        </section>
        <aside class="card guidance"><h2>Supported intake only</h2><p>Laptops, desktops, mobile phones, flat-panel monitors, printers, routers and keyboards.</p><p>Refrigerators, air conditioners, CRT, medical/industrial equipment and loose batteries are excluded.</p><div class="guidance-block"><h3>Unknown is a useful answer</h3><p>No answer is silently treated as safe or working. Safety concerns will need review before later collection planning.</p></div><div class="guidance-block"><h3>One physical item, one identity</h3><p>Use the same submission reference when retrying this form. A possible duplicate is a warning, not proof of another device's identity.</p></div></aside>
    </div>
@endsection
