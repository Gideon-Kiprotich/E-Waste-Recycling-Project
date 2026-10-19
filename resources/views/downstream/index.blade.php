@extends('layouts.devices')
@section('title','Downstream movements')
@section('intro','Reserve measured stock, record actual dispatch, then obtain separate recipient acknowledgment and completion evidence.')
@section('content')
<section class="card notice-content"><h2>Your source batches</h2>@forelse($batches as $b)<p><a href="{{ route('downstream.show',$b->subject_id) }}">{{ $b->public_code }} · Open downstream accounting</a></p>@empty<p>No committed batches in your current facility. Complete Dismantling / Batches first; draft selections claim no stock.</p>@endforelse{{ $batches->links() }}</section>
<section class="card notice-content"><h2>Named-recipient inbox</h2><p>Only shipments naming your current facility scope appear. Receipt does not itself establish completed processing.</p>@forelse($incoming as $h)<p><a href="{{ route('downstream.shipment',$h->id) }}">{{ $h->public_code }} · View shipment {{ $h->id }}</a></p>@empty<p>No shipments name your current active scope.</p>@endforelse{{ $incoming->links() }}</section>
@endsection
