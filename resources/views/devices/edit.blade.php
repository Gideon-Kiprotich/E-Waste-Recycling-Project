@extends('layouts.devices')
@php($fieldIds = array_merge(['data_status'=>'data_status','safety_concern'=>'safety_concern','physical_damage'=>'physical_damage','reason'=>'reason'], collect($tests)->mapWithKeys(fn ($label, $code) => ['answers.'.$code => 'answers_'.$code])->all()))
@section('title', 'Update self-report')
@section('intro', 'A new revision retains your earlier answers. Device identity and ownership stay fixed.')
@section('content')
<section class="card"><div class="card-heading"><h2>{{ $item->subject->public_code }} · Revision {{ $item->subject->version }}</h2></div>
<form method="POST" action="{{ route('items.update', $item->subject_id) }}" class="account-form">
    @csrf @method('PATCH')<input type="hidden" name="idempotency_key" value="{{ $commandKey }}"><input type="hidden" name="expected_version" value="{{ is_scalar(old('expected_version')) ? old('expected_version') : $item->subject->version }}">
    @include('devices.observations')
    <button class="primary" type="submit">Save self-report revision</button><a class="form-link" href="{{ route('items.show', $item->subject_id) }}">Back to device detail</a>
</form></section>
@endsection
