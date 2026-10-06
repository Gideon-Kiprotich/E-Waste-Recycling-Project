@extends('layouts.devices')
@php($isPart = $category === 'standalone_part')
@php($subtypes = \App\Support\Devices\Questions::subtypes($category))
@php($fieldIds = array_merge(array_combine(['category','subtype','model','identifier','photo','safety_concern','physical_damage','reason'], ['category','subtype','model','identifier','photo','safety_concern','physical_damage','reason']), $isPart ? ['identifier_kind'=>'identifier'] : ['identifier_kind'=>'identifier_kind','data_status'=>'data_status'], collect($tests)->mapWithKeys(fn ($label, $code) => ['answers.'.$code => 'answers_'.$code])->all()))
@section('title', $isPart ? 'Register a standalone part' : 'Register a device')
@section('intro', 'Register one physical item. Your private identifier and photo are optional.')
@section('content')
    <div class="workspace">
        <section class="card">
            <div class="card-heading"><h2>Item facts and self-report</h2></div>
            @if($selectionError && !$errors->any())<div class="notice-content"><x-feedback type="error" message="Choose a supported category and type, then load its questions. No unsupported intake form is available." /></div>@endif
            <form class="account-form category-picker" method="GET" action="{{ route('items.create') }}">
                <x-select-field name="category" label="Supported category" :options="$categories->pluck('label','code')->all()" :value="$category" />
                @if($subtypes)<x-select-field name="subtype" :label="$isPart ? 'Part type' : 'Peripheral subtype'" :options="$subtypes" :value="$subtype" />@endif
                <button class="secondary" type="submit">Load category questions</button><p class="hint">Choose the category first. Changing it reloads the form; unsaved answers are discarded.</p>
            </form>
            @if(count($tests))
                <form method="POST" action="{{ route('items.store') }}" enctype="multipart/form-data" class="account-form">
                    @csrf<input type="hidden" name="idempotency_key" value="{{ $commandKey }}"><input type="hidden" name="category" value="{{ $category }}">@if($subtype)<input type="hidden" name="subtype" value="{{ $subtype }}">@endif
                    <p><strong>{{ \App\Support\Devices\Questions::CATEGORIES[$category] }}{{ $subtype ? ' · '.($subtypes[$subtype] ?? '') : '' }}</strong></p>
                    <x-field name="model" label="Make / model" :required="false" :maxlength="150" hint="Leave blank if unknown." />
                    @unless($isPart)<x-select-field name="identifier_kind" label="Identifier type" :options="['serial'=>'Serial number','imei'=>'IMEI']" value="serial" />@endunless
                    <x-field name="identifier" :label="$isPart ? 'Serial number' : 'Serial / IMEI'" :required="false" :maxlength="100" :hint="$isPart ? 'Leave blank if unknown. A known serial can belong to only one registered item. Letters, digits, spaces and hyphens are allowed.' : 'Leave blank if unknown, missing or unreadable. Serials accept letters, digits, spaces and hyphens. IMEI requires 15 digits with a valid check digit; spaces and hyphens are allowed. It is private and cannot be changed through ordinary edits.'" />
                    <div class="field"><label for="photo">{{ $isPart ? 'Part' : 'Device' }} photo <span class="optional">(optional)</span></label><input id="photo" name="photo" type="file" accept="image/jpeg,image/png" aria-describedby="photo-hint{{ $errors->has('photo') ? ' photo-error' : '' }}" @if($errors->has('photo')) aria-invalid="true" @endif>
                        <p class="hint" id="photo-hint">JPEG/PNG, maximum 5 MiB and 16 megapixels. Do not include people, private screens, labels with sensitive details or stored data. Select the file again after a failed submission.</p>@error('photo')<p class="field-error" id="photo-error">{{ $message }}</p>@enderror
                    </div>
                    @include('devices.observations')
                    <button class="primary" type="submit">{{ $isPart ? 'Register part and create label' : 'Register device and create label' }}</button>
                </form>
            @else<p class="notice-content">Choose a supported category and its required type, then load the category questions.</p>@endif
        </section>
        <aside class="card guidance"><h2>Supported intake only</h2><p>Laptops, desktops, mobile phones, flat-panel monitors, printers, routers and keyboards; standalone HDDs, SSDs and small ICT cooling fans.</p><p>Loose batteries, mains/industrial fans, arbitrary components, bulk inventory, refrigerators, air conditioners, CRT and medical/industrial equipment are excluded.</p>
            @if($isPart)<div class="guidance-block"><h3>Already separate parts</h3><p>Register an already separate part. This registration does not record a parent device or removal history. Do not power, dismantle or inspect inside a part to complete this form.</p></div>@endif
            <div class="guidance-block"><h3>Unknown is a useful answer</h3><p>No answer is silently treated as safe or working. Safety concerns will need review before later collection planning. Registration does not approve collection or treatment.</p></div><div class="guidance-block"><h3>One physical item, one identity</h3><p>Use the same submission reference when retrying this form. A known serial/IMEI can belong to only one device, across all owners and categories. Recheck the number if it is already registered. Leave an unknown identifier blank.</p></div>
        </aside>
    </div>
@endsection
