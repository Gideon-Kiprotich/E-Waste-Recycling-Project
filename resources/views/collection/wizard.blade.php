@extends('layouts.devices')
@section('section-label', 'Collection requests')
@section('title', 'Request collection')
@section('intro', 'Use synthetic information for this study. Submission requests review; it does not book collection or transfer custody.')
@php
$fieldIds = ['items'=>'selected-items','estimated_kg'=>'selected-items','mode'=>'mode','point_id'=>'point_id','service_area_id'=>'service_area_id','address'=>'address','latitude'=>'latitude','longitude'=>'longitude','pickup_point'=>'pickup_point','contact'=>'contact','preferred_date'=>'preferred_date','privacy_ack'=>'privacy_ack'];
foreach ($items as $device) { $fieldIds['estimated_kg.'.$device->subject_id] = 'mass-'.$device->subject_id; }
if ($step !== 1) { $fieldIds['items'] = 'registration-heading'; $fieldIds['estimated_kg'] = 'registration-heading'; }
if ($step === 4) { foreach (['mode','point_id','service_area_id','address','latitude','longitude','contact','preferred_date'] as $field) { $fieldIds[$field] = 'registration-heading'; } }
@endphp
@section('content')
<ol class="request-steps" aria-label="Request progress">
@foreach(['Devices','Collection method','Location','Review'] as $label)<li @if($loop->iteration === $step) aria-current="step" @endif>Step {{ $loop->iteration }} of 4 · {{ $label }}</li>@endforeach
</ol>
<div class="workspace">
<section class="card"><div class="card-heading"><h2>Step {{ $step }} of 4 · {{ ['Devices','Collection method','Location','Review'][$step-1] }}</h2></div>
<form class="account-form" method="POST" action="{{ route('requests.advance', ['step'=>$step]) }}">@csrf
<input type="hidden" name="draft_version" value="{{ $draftVersion }}">
@if($step === 1)
<fieldset id="selected-items" tabindex="-1" @if($errors->has('items') || $errors->has('estimated_kg')) aria-describedby="items-error" @endif><legend>Select your devices or parts</legend>
<p>Choose up to 25 items. Estimate each item's mass in kg; this is not a measured weight. No powering, opening or testing is needed.</p>
@forelse($items as $device)
<div class="request-choice">
<div class="acknowledgment"><input type="checkbox" id="item-{{ $device->subject_id }}" name="items[]" value="{{ $device->subject_id }}" @checked(in_array($device->subject_id,$fields['items'] ?? [],true)) @disabled(!$eligibility[$device->subject_id]['allowed']) @if($errors->has('items')) aria-invalid="true" @endif aria-describedby="eligibility-{{ $device->subject_id }}{{ $errors->has('items') ? ' items-error' : '' }}">
<label for="item-{{ $device->subject_id }}">{{ $device->subtypeLabel() ?? $device->category->label }} · {{ $device->model }}<br><span class="manual-code">{{ $device->subject->public_code }}</span></label></div>
<p class="hint" id="eligibility-{{ $device->subject_id }}">{{ $eligibility[$device->subject_id]['message'] }}</p>
@if($eligibility[$device->subject_id]['allowed'])<div class="field"><label for="mass-{{ $device->subject_id }}">Estimated kg for this item</label><input id="mass-{{ $device->subject_id }}" name="estimated_kg[{{ $device->subject_id }}]" type="number" min="0.001" max="999999999.999" step="0.001" value="{{ $fields['estimated_kg'][$device->subject_id] ?? '' }}" @if($errors->has('estimated_kg.'.$device->subject_id)) aria-invalid="true" aria-describedby="mass-error-{{ $device->subject_id }}" @endif>
@error('estimated_kg.'.$device->subject_id)<p class="field-error" id="mass-error-{{ $device->subject_id }}">{{ $message }}</p>@enderror</div>@endif
</div>
@empty<p>No registered items yet. <a href="{{ route('items.create') }}">Register a device or part first.</a></p>@endforelse
@if($errors->has('items') || $errors->has('estimated_kg'))<p class="field-error" id="items-error">{{ $errors->first('items') ?: $errors->first('estimated_kg') }}</p>@endif
</fieldset>
@elseif($step === 2)
<x-select-field name="mode" label="Collection method" :options="['pickup'=>'Pickup request','drop_off'=>'Drop off at a compatible point']" :value="$fields['mode'] ?? ''" hint="Neither method is a confirmed appointment. Maps are not required." />
@elseif($step === 3)
<input type="hidden" name="mode" value="{{ $fields['mode'] }}">
@php($areaOptions = $areas->mapWithKeys(fn($area)=>[$area->id=>$area->version_code.($area->synthetic ? ' · Synthetic study boundary, not official' : ' · '.$area->provenance)])->all())
<x-select-field name="service_area_id" label="Study service area" :options="$areaOptions" :value="$fields['service_area_id'] ?? ''" hint="The local synthetic rectangle spans latitude −1.350000 to −1.200000 and longitude 36.750000 to 36.900000. Its boundary is included. This is not the official Nairobi County boundary." />
@if($areas->isEmpty())<x-feedback type="info" message="No active service area is configured. Local demonstration setup is required before submission." />@endif
@if($fields['mode'] === 'pickup')
<x-field name="address" label="Pickup address and landmark / entrance instructions" :value="$fields['address'] ?? ''" maxlength="500" autocomplete="off" hint="Describe where to meet at the selected point. Private to your request; use synthetic information during evaluation." />
<x-pickup-location :fields="$fields" :confirmed="$pickupConfirmed" />
@else
@php($pointOptions = $points->mapWithKeys(fn($point)=>[$point->id=>$point->name.($point->synthetic ? ' · Synthetic, no real acceptance' : '')])->all())
<x-select-field name="point_id" label="Compatible drop-off point" :options="$pointOptions" :value="$fields['point_id'] ?? ''" hint="Only currently active points accepting every selected category/subtype are listed. Final submission checks again." />
@if($points->isEmpty())<x-feedback type="info" message="No active point explicitly accepts all selected items. Go back to change the method or selection; no alternative is chosen automatically." />@endif
@endif
<x-field name="contact" label="Contact information" :value="$fields['contact'] ?? ''" maxlength="150" autocomplete="off" hint="Phone or other contact for this request. Use synthetic information for study evaluation." />
<x-field name="preferred_date" label="Preferred date" type="date" :value="$fields['preferred_date'] ?? ''" hint="A preference only; no pickup or drop-off appointment is confirmed." />
@else
<dl class="facts"><dt>Method</dt><dd>{{ $fields['mode'] === 'pickup' ? 'Pickup request' : 'Drop-off request' }}</dd><dt>Preferred date</dt><dd>{{ $fields['preferred_date'] }}</dd><dt>Contact (private)</dt><dd>{{ $fields['contact'] }}</dd>
@if($fields['mode'] === 'pickup')<dt>Address (private)</dt><dd>{{ $fields['address'] }}</dd><dt>Pickup point (private)</dt><dd>{{ $pickupConfirmed ? 'Selected point confirmed.' : 'Pickup point needs confirmation.' }} <a href="{{ route('requests.step',['step'=>3]) }}">Review or adjust location</a></dd>@else<dt>Point</dt><dd>{{ $points->firstWhere('id',$fields['point_id'])?->name ?? 'Point unavailable — return to Location' }}</dd>@endif
<dt>Estimated total</dt><dd>{{ number_format(array_sum($fields['estimated_kg']),3) }} kg · estimates only</dd><dt>Service area</dt><dd>{{ $areas->firstWhere('id',$fields['service_area_id'])?->version_code ?? 'Area inactive' }} · local study, not official county boundary</dd></dl>
<input type="hidden" name="idempotency_key" value="{{ $fields['idempotency_key'] }}">
<div class="acknowledgment"><input type="checkbox" id="privacy_ack" name="privacy_ack" value="1" required @if($errors->has('privacy_ack')) aria-invalid="true" aria-describedby="privacy_ack-error" @endif><label for="privacy_ack">I acknowledge that this request stores my selected items and private contact/location information under the <a href="{{ route('privacy.notice') }}">privacy notice</a>. Submission requests review and does not transfer physical custody.</label></div>
@error('privacy_ack')<p id="privacy_ack-error" class="field-error">{{ $message }}</p>@enderror
@endif
<div class="actions">@if($step > 1)<a class="secondary button-link" href="{{ $step === 2 ? route('requests.create') : route('requests.step',['step'=>$step-1]) }}">Back</a>@endif<button class="primary" type="submit">{{ $step === 4 ? 'Submit request' : 'Continue' }}</button></div>
</form></section>
<aside class="card guidance"><h2>Selected items</h2><ul>@forelse($selected as $device)<li>{{ $device->subtypeLabel() ?? $device->category->label }} · {{ $fields['estimated_kg'][$device->subject_id] ?? 'Unknown' }} kg<p class="hint">{{ $eligibility[$device->subject_id]['message'] }}</p></li>@empty<li>No items selected yet.</li>@endforelse</ul><div class="guidance-block"><h3>Awaiting review</h3><p>Safety Unknown/Yes and unresolved fan scope/electronics require additional human review. Unknown past function alone is not a hazard clearance or a rejection.</p><p>No dispatch, physical collection or notification delivery is implemented. Synthetic points do not offer real services.</p></div><form method="POST" action="{{ route('requests.restart') }}">@csrf<button class="secondary" type="submit">Start a fresh request</button></form></aside>
</div>
@endsection
