@extends('layouts.devices')
@section('title','Custody handovers')
@section('section-label','Acknowledged transfers · No processing authorization')
@section('intro','Initiation leaves confirmed custody with the sender. Only the named recipient can acknowledge actual physical receipt. Disputed physical whereabouts may differ from recorded custody.')
@section('content')
<section class="card"><h2>Items currently held and available</h2><p>Active pending/disputed transfers are excluded. Completed pickup assignments do not prevent handover of received items.</p>
@forelse($held as $row)<p class="manual-code">{{ $row->public_code }}</p><p>{{ $row->category_label }} {{ $row->subtype ? ' · '.str_replace('_',' ',$row->subtype) : '' }} · {{ $row->model ?? 'Model unknown' }}</p>@empty<p>No unclaimed received items in your current collecting scope. Facility redistribution is deferred. Ask an Administrator to check place/operator scope if needed.</p>@endforelse
</section>
@if($canInitiate)<section class="card"><h2>Choose an eligible receiving Processor</h2><p>Prepare explicit category/subtype capabilities in Operators and places and named Processor access in Place staff access. A destination must accept every selected item; no Collector relay is supported.</p>
@forelse($recipients as $r)<p><a href="{{ route('handovers.create',$r->id) }}">Prepare handover to {{ $r->display_name }} · {{ $r->name }}</a> · {{ str_replace('_',' ',$r->kind) }} · {{ $r->operator_name }}</p>@empty<p>No active named Processor at an eligible reviewed destination. Ask an Administrator to finish operator/place review and Place staff access; no scope is inferred.</p>@endforelse</section>@endif
<section class="card"><h2>Outgoing pending, incoming and retained handovers</h2><p>Incoming work requires named-recipient acknowledgment. Administrators can review discrepancies or withdraw pending items after scope loss, without acknowledging for anyone.</p>
@forelse($handovers as $h)<p><a href="{{ route('handovers.show',$h->id) }}">Open handover {{ $h->id }}</a> · {{ ucfirst($h->state) }} · v{{ $h->version }} @if($h->receiver_actor_id===auth()->id()) · Incoming {{ in_array($h->state,['pending','partial','disputed'],true)?'— inspect outstanding outcomes':'— retained record' }} @elseif($h->sender_actor_id===auth()->id()) · Outgoing @endif</p>@empty<p>No handovers visible in your current scope.</p>@endforelse
</section>
@endsection
