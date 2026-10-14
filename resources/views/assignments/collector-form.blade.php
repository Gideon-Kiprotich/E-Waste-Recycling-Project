@extends('layouts.devices')
@section('section-label','Administrator · Collector access')
@section('title',$entity ? 'Manage Collector access' : 'Create Collector account')
@section('intro','Grant one operator scope. Share credentials privately; no external email is sent.')
@php($fieldIds=array_combine(['display_name','email','password','password_confirmation','organization_ref','reason','operation'],['display_name','email','password','password_confirmation','organization_ref','reason','operation']))
@section('content')
<section class="card"><form method="POST" action="{{ $entity ? route('admin.collectors.update',$entity->id) : route('admin.collectors.store') }}" class="account-form">@csrf
<input type="hidden" name="idempotency_key" value="{{ old('idempotency_key',\Illuminate\Support\Str::uuid()->toString()) }}">
@if($entity)<p>Current operator: {{ $currentOperator?->name ?? 'Unscoped — no operator link' }} · membership {{ $entity->active ? 'Active' : 'Inactive' }}. An unchanged active link keeps sessions and assignments.</p><p>{{ $entity->display_name }} · account {{ $entity->status }} · membership v{{ $entity->version }}</p><input type="hidden" name="expected_version" value="{{ $entity->version }}"><x-select-field name="operation" label="Access action" :options="['link'=>'Link/reactivate at selected operator','deactivate'=>'Deactivate membership']" />
@else<input type="hidden" name="operation" value="create"><x-field name="display_name" label="Collector display name" maxlength="100" /><x-field name="email" label="Collector sign-in email" type="email" maxlength="254" autocomplete="off" /><x-field name="password" label="Private initial password" type="password" maxlength="128" autocomplete="new-password" hint="12–128 characters. Share privately; never include credentials in reasons." /><x-field name="password_confirmation" label="Confirm private password" type="password" maxlength="128" autocomplete="new-password" />@endif
<x-select-field name="organization_ref" label="Reviewed collector operator" :options="$options" :value="$organizationValue" :required="false" hint="Required for creation/linking. Deactivation retains previous scope in history." /><x-field name="reason" label="Private access-change reason" maxlength="1000" /><button class="primary" type="submit">Save Collector access</button></form></section>
@endsection
