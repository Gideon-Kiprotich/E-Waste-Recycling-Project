@extends('layouts.devices')
@section('title', 'Device identity label')
@section('intro', 'Reprinting this label keeps the same permanent reference.')
@section('content')
    <div class="actions no-print"><button class="primary" type="button" data-print-label>Print label</button><a class="secondary button-link" href="{{ route('items.qr', ['item'=>$item->subject_id,'download'=>1]) }}">Download QR PNG</a><a href="{{ route('items.show', $item->subject_id) }}">Back to detail</a></div>
    <section class="identity-label card notice-content"><h2>E-Waste Connect · {{ $item->intake_class === 'processor_derived' ? 'Media' : ($item->isPart() ? 'Part' : 'Device') }} identity</h2><p>{{ $item->category->label }}{{ $item->subtypeLabel() ? ' · '.$item->subtypeLabel() : '' }}</p>@if($item->model)<p>{{ $item->model }}</p>@endif<img class="qr-image" src="{{ route('items.qr', $item->subject_id) }}" alt="Authenticated lookup QR"><p class="manual-code">{{ $item->subject->public_code }}</p><p>Manual lookup available in My devices. Authentication and ownership required.</p></section>
@endsection
