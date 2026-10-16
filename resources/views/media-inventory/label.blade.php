@extends('layouts.devices')
@section('title','Media identity label')
@section('intro','A replacement label retains the same physical subject. It grants no access and establishes no receipt or treatment.')
@section('content')
<div class="actions no-print"><button class="primary" type="button" data-print-label>Print label</button><a href="{{ route('sanitization.show',$row->subject_id) }}">Back to media history</a></div>
<section class="identity-label card notice-content"><h2>E-Waste Connect · Media identity</h2><p>{{ strtoupper($row->subtype) }} · {{ $row->model ?? 'Model unknown' }}</p><img class="qr-image" src="{{ route('media-inventory.qr',$row->subject_id) }}" alt="Authenticated media lookup QR"><p class="manual-code">{{ $row->public_code }}</p><p>No personal information in the code. Use safe external placement; do not dismantle or label internal hardware to use this screen.</p></section>
@endsection
