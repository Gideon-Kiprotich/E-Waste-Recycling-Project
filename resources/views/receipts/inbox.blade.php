@extends('layouts.devices')
@section('title','Point intake')
@section('section-label','Processor · Collection-point receipt')
@section('intro','Only outstanding drop-off requests for your explicitly assigned point. Intake is separate from processing or sanitization.')
@section('content')
@if(!$eligible)<x-feedback type="info" message="No active eligible point scope. Ask an Administrator to open Point staff access and check the operator, point approval and capabilities. Sign in again after a scope change." />
@else<section class="card guidance"><h2>{{ $p->name }}</h2><p>{{ $p->synthetic ? 'Synthetic study point; real operational acceptance unverified.' : 'Directory approval does not establish real operational acceptance.' }}</p><ul>@forelse($requests as $r)<li><span class="manual-code">{{ $r->public_code }}</span> · {{ \App\Support\Collection\Review::LABELS[$r->review_state] }} · <a href="{{ route('receipts.form',$r->id) }}">Open drop-off visit</a></li>@empty<li>No outstanding drop-off requests for this point.</li>@endforelse</ul><p>A current Ready review is checked when opening and submitting. After a partial or failed visit, ask the Administrator for fresh Request review.</p></section>@endif
@endsection
