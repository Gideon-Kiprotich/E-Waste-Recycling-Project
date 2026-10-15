@extends('layouts.devices')
@section('title','Prepare custody handover')
@section('intro','Select received items in your current custody. Confirm the manifest; the named Processor must acknowledge separately.')
@php($fieldIds=array_fill_keys(['subjects','reason','sender_attested'],'manifest'))
@section('content')
<section class="card"><h2>{{ $receiver->display_name }} · {{ $receiver->name }}</h2><p>{{ $receiver->operator_name }} · {{ str_replace('_',' ',$receiver->kind) }}</p><h3>Explicit destination capabilities</h3>@forelse($capabilities as $c)<p>{{ $c->label }} {{ $c->subtype ? ' · '.str_replace('_',' ',$c->subtype) : '' }}</p>@empty<p>No declared capabilities. Ask the Administrator to configure this place before initiating.</p>@endforelse</section>
<section class="card"><form method="POST" action="{{ route('handovers.store') }}" enctype="multipart/form-data" class="account-form">@csrf
<input type="hidden" name="recipient_ref" value="{{ $receiver->id.':'.$receiver->version }}"><input type="hidden" name="destination_version" value="{{ $receiver->destination_version }}"><input type="hidden" name="operator_version" value="{{ $receiver->operator_version }}"><input type="hidden" name="idempotency_key" value="{{ old('idempotency_key',\Illuminate\Support\Str::uuid()->toString()) }}">
<fieldset id="manifest" tabindex="-1"><legend>Manifest — choose currently held items</legend>
@forelse($held as $row)<div class="acknowledgment"><input type="checkbox" id="subject-{{ $row->subject_id }}" name="subjects[]" value="{{ $row->subject_id.':'.$row->version }}" @if($errors->has('subjects')) aria-invalid="true" aria-describedby="subjects-error" @endif><label for="subject-{{ $row->subject_id }}">{{ $row->public_code }} · {{ $row->category_label }} {{ $row->subtype ? ' · '.str_replace('_',' ',$row->subtype) : '' }} · {{ $row->model ?? 'Model unknown' }}</label></div>@empty<p>No available received items. <a href="{{ route('handovers.index') }}">Return to Custody handovers</a>.</p>@endforelse
@error('subjects')<p class="field-error" id="subjects-error">{{ $message }}</p>@enderror
<x-field name="reason" label="Private manifest description" maxlength="1000" />
<div class="acknowledgment"><input id="sender_attested" name="sender_attested" type="checkbox" value="1" required @if($errors->has('sender_attested')) aria-invalid="true" aria-describedby="sender_attested-error" @endif><label for="sender_attested">I confirm this selected manifest as the authorized sender. Initiation does not establish receipt by the destination.</label></div>@error('sender_attested')<p class="field-error" id="sender_attested-error">{{ $message }}</p>@enderror</fieldset>
@include('handovers.evidence-fields')
<button class="primary">Initiate pending handover</button></form></section>
@endsection
