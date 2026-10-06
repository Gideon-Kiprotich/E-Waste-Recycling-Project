@extends('layouts.devices')
@section('section-label', 'Collection requests')
@section('title','Request detail')
@section('intro','Only your own request is shown. Changes do not create a scheduled appointment.')
@php($fieldIds = ['items'=>'change-items','reason'=>'reason','preferred_date'=>'preferred_date','operation'=>'operation'])
@section('content')
<section class="card guidance"><h2 class="manual-code">{{ $record->public_code }}</h2><p>{{ $record->state === 'SUBMITTED' ? 'Awaiting review' : str_replace('_',' ',ucfirst(strtolower($record->state))) }} · version {{ $record->version }}</p>
<p><strong>{{ \App\Support\Collection\Review::LABELS[$reviewState] }}</strong>. {{ $reviewState === 'ready' ? 'Current review checks passed for later allocation; no booking or collection is confirmed.' : 'Outstanding items require current review before any later allocation. A hold or changed configuration does not cancel your reservation.' }}</p>
<x-feedback type="info" message="Submission is not physical collection. All outstanding items await human review. Review-needed items are withheld from ordinary scheduling; no appointment or delivery is promised." />
<dl class="facts"><dt>Method</dt><dd>{{ $record->mode === 'pickup' ? 'Pickup request' : 'Drop off' }}</dd><dt>Submitted date preference</dt><dd>{{ $record->preferred_date }} · see current per-item preferences below</dd><dt>Contact (private)</dt><dd>{{ $contact }}</dd>
@if($address)<dt>Address (private)</dt><dd>{{ $address }}</dd><dt>Coordinates (private)</dt><dd>{{ $private->latitude }}, {{ $private->longitude }}</dd>@endif
@if($point)<dt>Point</dt><dd>{{ $point->name }}{{ $point->synthetic ? ' · Synthetic; no real acceptance' : '' }}{{ !$point->active ? ' · Currently inactive' : '' }}</dd>@endif
<dt>Area version</dt><dd>{{ $area->version_code }} · {{ $area->provenance }}</dd></dl></section>
<div class="device-grid">@foreach($rows as $row)@php($device = $items[$row->item_id])<article class="card guidance"><h2><a href="{{ route('items.show',$device->subject_id) }}">{{ $device->subtypeLabel() ?? $device->category->label }}</a></h2><p class="manual-code">{{ $device->subject->public_code }}</p><p>{{ ucfirst($row->outcome) }} · item state {{ $device->processing_state }}</p><p>Current preferred date: {{ $row->preferred_date }} (not booked)<br>Estimated {{ number_format((float)$row->estimated_kg,3) }} kg</p><p>Original self-report v{{ $row->initial_subject_version }}; current v{{ $row->current_subject_version }} · {{ $row->eligibility_version }}</p><ul>@foreach(json_decode($row->review_reasons,true) as $code)<li>{{ \App\Support\Collection\Eligibility::REASONS[$code] }}</li>@endforeach</ul></article>@endforeach</div>
@if($rows->contains(fn($row)=>$row->active))
<section class="card"><div class="card-heading"><h2>Change outstanding items</h2></div><form method="POST" action="{{ route('requests.change',$record->id) }}" class="account-form">@csrf
<input type="hidden" name="expected_version" value="{{ $record->version }}"><input type="hidden" name="idempotency_key" value="{{ old('idempotency_key',\Illuminate\Support\Str::uuid()->toString()) }}">
<fieldset id="change-items" tabindex="-1"><legend>Select outstanding items</legend>@foreach($rows as $row)@if($row->active && $items[$row->item_id]->processing_state === 'registered')<div class="acknowledgment"><input id="change-{{ $row->id }}" type="checkbox" name="items[]" value="{{ $row->item_id }}" @checked(in_array($row->item_id, old('items', []), true)) @if($errors->has('items')) aria-invalid="true" aria-describedby="change-items-error" @endif><label for="change-{{ $row->id }}">{{ $items[$row->item_id]->subtypeLabel() ?? $items[$row->item_id]->category->label }} · {{ $items[$row->item_id]->subject->public_code }}</label></div>@endif
@endforeach
@error('items')<p id="change-items-error" class="field-error">{{ $message }}</p>@enderror</fieldset>
<x-select-field name="operation" label="Change" :options="['cancel'=>'Cancel selected outstanding items','date'=>'Change their preferred date']" />
<x-field name="preferred_date" label="New preferred date (date change only)" type="date" :required="false" hint="Leave blank for cancellation. This preference is not a booking." />
<x-field name="reason" label="Reason for change" maxlength="1000" hint="Retained privately in request history. Do not include credentials or other people's personal information." />
<button type="submit" class="primary">Save request change</button></form></section>
@endif
<section class="card guidance section-title"><h2>Actual request history</h2><ol class="timeline">@foreach($history as $event)@php($payload=json_decode($event->safe_payload,true))<li><strong>{{ str_replace('_',' ',ucfirst($event->type)) }}</strong><p>{{ \Carbon\CarbonImmutable::parse($event->recorded_at)->timezone('Africa/Nairobi')->format('j M Y H:i') }} · version {{ $event->aggregate_version }}</p>@if(isset($payload['preferred_date']))<p>Preference: {{ $payload['preferred_date'] }}</p>@endif @if(isset($payload['reason_ciphertext']))<p>Reason: {{ \Illuminate\Support\Facades\Crypt::decryptString($payload['reason_ciphertext']) }}</p>@endif</li>@endforeach</ol>{{ $history->links('devices.pagination') }}</section>
@endsection
