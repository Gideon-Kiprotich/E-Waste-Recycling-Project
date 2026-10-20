@extends('layouts.devices')
@section('section-label','Operational restriction review')
@section('title','Operational restriction review')
@section('intro','Investigate recorded restrictions. Closing a case does not establish safety, receipt or physical work.')
@section('content')
<p><a href="{{ route('restrictions.notices') }}">Restriction review notices</a></p>
@if($admin)
<section class="card notice-content"><h2>Source restrictions</h2><p>These are existing observations, not historical investigation cases. Opening this page creates nothing.</p>
@forelse($sources as $s)<p><a href="{{ route('restrictions.source',[$s->source_kind,$s->source_id,$s->fact_key]) }}">{{ $s->public_code }} · {{ ucfirst($s->source_kind) }} / {{ str_replace('_',' ',$s->fact_key) }}</a> · {{ $s->supported ? 'Recording-error review supported' : 'Investigation only — resolution unavailable' }}</p>@empty<p>No projected restrictions in this queue. Do not create test restrictions in development merely to populate it.</p>@endforelse
{{ $sources->links() }}</section>
@endif
<section class="card notice-content"><h2>{{ $admin ? 'Investigation cases' : ($consumer ? 'Your review milestones' : 'Your currently authorized investigations') }}</h2>
@forelse($cases as $c)<p><a href="{{ route('restrictions.show',$c->id) }}">{{ $c->public_code }} · {{ str_replace('_',' ',$c->state) }}</a></p>@empty<p>No cases are available in your current scope. Processor investigation requires explicit assignment and, before receipt, a case-specific grant. Scope loss or closure ends staff access.</p>@endforelse
{{ $cases->links() }}</section>
@endsection
