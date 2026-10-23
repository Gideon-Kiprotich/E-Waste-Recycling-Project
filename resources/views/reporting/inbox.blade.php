@extends('layouts.access')
@section('title','Unified inbox')
@section('intro','Delivered means persisted here in-app. Read state is separate.')
@section('content')
<h2>Unified inbox</h2><p><a href="{{ route('dashboard') }}">Workspace</a> · <a href="{{ route('reports.index') }}">Scoped reports</a></p><x-feedback />
@if($unavailable)<p role="status">{{ $unavailable }} notice deliveries failed or became unavailable. Authorized operators can review delivery; your workflow history remains authoritative.</p>@endif
@forelse($notices as $n)<article class="card guidance"><h3>{{ \App\Support\Notices\Catalogue::label($n->type) }}</h3><p><x-instant :value="$n->recorded_at" /> · {{ $n->read_at ? 'Read' : 'Unread' }} · {{ $n->family }}</p><p><a href="{{ $n->url }}">Open current authorized details / workspace</a>. Historical notice delivery grants no private access.</p>@if(!$n->read_at)<form method="POST" action="{{ route('inbox.read',[$n->family,$n->id]) }}">@csrf<button class="secondary">Mark read</button></form>@endif</article>@empty<p>No delivered notices. New supported events are delivered by the configured in-app worker; older backlog is not replayed.</p>@endforelse{{ $notices->links('devices.pagination') }}
@if(auth()->user()->role()===\App\Enums\Role::Administrator)<section class="card"><h3>Delivery review</h3>@forelse($failures as $failure)<p>{{ $failure->id }} · {{ $failure->state }} · attempts {{ $failure->attempts }} · {{ $failure->delivery_error }}</p>@if($failure->state==='failed')<form method="POST" action="{{ route('inbox.retry',$failure->id) }}">@csrf<button class="secondary">Retry supported delivery with current authority</button></form>@endif @empty<p>No failed or suppressed deliveries.</p>@endforelse</section>@endif
@endsection
