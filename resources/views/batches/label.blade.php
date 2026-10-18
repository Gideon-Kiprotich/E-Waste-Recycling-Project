@extends('layouts.devices')
@section('title','Batch / output identity label')
@section('intro','Reprinting retains the same permanent reference. A label is not proof of processing completion.')
@section('content')
<div class="actions no-print"><button type="button" data-print-label>Print label</button><a href="{{ route('batches.label',['subject'=>$row->id,'qr'=>1]) }}">View QR PNG</a><a href="{{ route('batches.show',$batch) }}">Back to batch</a></div>
<section class="identity-label card notice-content"><h2>E-Waste Connect · {{ $row->kind==='item' ? 'Canonical media' : ucfirst($row->kind) }}</h2><img class="qr-image" src="{{ route('batches.label',['subject'=>$row->id,'qr'=>1]) }}" alt="Authenticated batch lookup QR"><p class="manual-code">{{ $row->public_code }}</p><p>Manual lookup: Dismantling / Batches → Find batch or output. Active facility scope required. No private identifier or owner is encoded.</p></section>
@endsection
