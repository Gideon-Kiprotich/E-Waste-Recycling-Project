@extends('layouts.devices')
@section('title','Your facility-identified medium')
@section('intro','This canonical physical identity was identified at a facility; no earlier first receipt, transfer or removal history is invented.')
@section('content')
<section class="card notice-content"><h2>{{ $item->subtypeLabel() }} · {{ $item->model ?? 'Model unknown' }}</h2><p class="manual-code">{{ $item->subject->public_code }}</p><p>Data-bearing YES — permanently. This observation does not establish treatment or release.</p><p><a href="{{ route('media-inventory.show',$origin->parent_id) }}">View your parent media coverage</a> · <a href="{{ route('sanitization.show',$item->subject_id) }}">View your media treatment milestones</a></p><p><a href="{{ route('items.label',$item->subject_id) }}">Printable label</a> · <a href="{{ route('items.qr',$item->subject_id) }}">Download QR PNG</a></p></section>
@endsection
