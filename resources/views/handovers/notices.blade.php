@extends('layouts.devices')
@section('title','Handover notices')
@section('intro','Safe event notices. Opening a link checks current access; old notices do not preserve private-detail access. No inbox delivery is claimed.')
@section('content')
<section class="card">@forelse($notices as $n)<article><h2>Handover update · {{ ucfirst($n->kind) }}</h2><p>{{ \Carbon\CarbonImmutable::parse($n->recorded_at)->timezone('Africa/Nairobi')->format('j M Y H:i') }} Africa/Nairobi</p><a href="{{ route('handovers.show',$n->handover_id) }}">Open authorized handover details</a>@if(!$n->read_at)<form method="POST" action="{{ route('handovers.notices.read',$n->id) }}">@csrf<button class="secondary">Mark read</button></form>@else<p>Read</p>@endif</article>@empty<p>No delivered handover notices.</p>@endforelse</section>
@endsection
