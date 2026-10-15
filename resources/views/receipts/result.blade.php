@extends('layouts.devices')
@section('title','Recorded visit')
@section('intro','Only explicit outcomes were recorded. Untouched items remain not attempted. First receipt does not establish later handover, facility delivery, processing or sanitization.')
@section('content')
<section class="card guidance"><h2 class="manual-code">{{ $r->public_code }}</h2><p>Observed {{ \Carbon\CarbonImmutable::parse($record->observed_at)->timezone('Africa/Nairobi')->format('j M Y H:i') }} EAT · recorded {{ \Carbon\CarbonImmutable::parse($record->recorded_at)->timezone('Africa/Nairobi')->format('j M Y H:i') }} EAT</p><ul>@foreach($outcomes as $o)<li><span class="manual-code">{{ $o->public_code }}</span> · {{ str_replace('_',' ',ucfirst($o->outcome)) }}@if($private)<p>{{ \Illuminate\Support\Facades\Crypt::decryptString($o->observation_ciphertext) }}</p>@endif</li>@endforeach</ul>
@if($reference)<p>Operator reference: {{ $reference['issuer'] }} · {{ $reference['code'] }}. {{ $reference['provenance'] }}.</p>@endif
@if($private && $record->attachment_id)<p><a href="{{ route('receipts.evidence',$record->id) }}">Download private visit evidence</a></p>@endif
@if(auth()->user()->role()===\App\Enums\Role::Collector)<p><a href="{{ route('collector.routes.index') }}">Continue Approved routes</a>. Finish tour work explicitly when done; this does not record depot arrival.</p>@elseif(auth()->user()->role()===\App\Enums\Role::Processor)<a href="{{ route('processor.intake') }}">Return to Point intake</a>@elseif(auth()->user()->role()===\App\Enums\Role::Consumer)<a href="{{ route('requests.show',$r->id) }}">My request</a>@endif
<p>Any remainder requires fresh Request review. Received item ownership is unchanged; custody records identify the actual recipient separately.</p></section>
@endsection
