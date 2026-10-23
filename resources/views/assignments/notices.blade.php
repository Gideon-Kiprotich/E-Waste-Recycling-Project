@extends('layouts.devices')
@section('section-label','Private · Assignment notices')
@section('title','Assignment notices')
@section('intro','Safe summaries of committed assignment events. Open the current page to check whether access and eligibility remain valid.')
@section('content')
<div class="device-grid">@forelse($notices as $notice)<article class="card guidance"><h2>{{ \App\Support\Assignments\History::LABELS[substr($notice->type,11)] }}</h2><p class="manual-code">{{ $notice->public_code }}</p><p>{{ $notice->read_at ? 'Read' : 'Unread' }} · <x-instant :value="$notice->recorded_at" /></p>
@if(auth()->user()->role() === \App\Enums\Role::Administrator)<a href="{{ route('admin.assignments.show',$notice->request_id) }}">Current allocation</a>@elseif(auth()->user()->role() === \App\Enums\Role::Collector)<a href="{{ route('collector.assignments.show',$notice->assignment_id) }}">Current assignment</a>@elseif(auth()->user()->role() === \App\Enums\Role::Consumer)<a href="{{ route('requests.show',$notice->request_id) }}">My request</a>@endif
@if(!$notice->read_at)<form method="POST" action="{{ route('assignment.notices.read',$notice->id) }}">@csrf<button class="secondary" type="submit">Mark read</button></form>@endif</article>@empty<x-feedback type="info" message="No delivered assignment notices. The assignment screens remain authoritative; in-app delivery may be pending." />@endforelse</div>{{ $notices->links('devices.pagination') }}
@endsection
