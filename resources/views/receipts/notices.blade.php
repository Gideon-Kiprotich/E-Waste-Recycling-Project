@extends('layouts.devices')
@section('title','Receipt notices')
@section('intro','Safe owned visit milestones. In-app notice delivery is separate from the recorded outcome; no external email is sent.')
@section('content')
@forelse($notices as $n)<section class="card guidance"><h2>Physical visit recorded</h2><p class="manual-code">{{ $n->public_code }}</p><p>{{ $n->read_at?'Read':'Unread' }} · {{ \Carbon\CarbonImmutable::parse($n->recorded_at)->timezone('Africa/Nairobi')->format('j M Y H:i') }}</p><a href="{{ route('receipts.result',$n->visit_id) }}">View safe visit outcomes</a>@if(!$n->read_at)<form method="POST" action="{{ route('receipts.notices.read',$n->id) }}">@csrf<button class="secondary">Mark read</button></form>@endif</section>@empty<x-feedback type="info" message="No delivered receipt notices. Your request history remains authoritative; delivery may be pending." />@endforelse
@endsection
