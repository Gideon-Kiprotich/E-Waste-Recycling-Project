@extends('layouts.access')
@section('title','Item timeline')
@section('intro','Recorded physical history and current status remain separate.')
@section('content')
<h2>Item timeline · {{ $item->public_code }}</h2><p><a href="{{ route('items.show',$item->subject_id) }}">Open item (current authorization required)</a> · <a href="{{ route('inbox.index') }}">Inbox</a></p>
<section class="card"><h3>Current status</h3><p>{{ $status['stage'] }}. {{ $status['outcome'] }}</p><p>{{ $status['origin'] }}</p><p>Batch associations show recorded milestones. Batch mass and other members are outside this item timeline.</p></section>
<ol class="timeline">@forelse($events as $event)<li><h3>{{ $event['label'] }}</h3><p>Observed <x-instant :value="$event['observed_at']" /> · recorded <x-instant :value="$event['recorded_at']" /></p><p>Original event reference {{ $event['id'] }} · version {{ $event['version'] }}@if($event['reference']) · corrective review reference {{ $event['reference'] }}@endif</p></li>@empty<li>No proven events.</li>@endforelse</ol>{{ $events->links('reporting.cursor') }}
@endsection
