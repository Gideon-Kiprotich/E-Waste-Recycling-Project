@extends('layouts.devices')
@section('title','Assessment notices')
@section('intro','Private-detail-free in-app notices. Following a link checks current access again; no inbox delivery is claimed.')
@section('content')
@forelse($rows as $notice)<section class="card notice-content"><p>Assessment activity was recorded. No work completion, sanitization or release is implied.</p><p><a href="{{ route('assessments.show',$notice->subject_id) }}">Open authorized assessment history</a></p>@if(!$notice->read_at)<form method="POST" action="{{ route('assessments.notices.read',$notice->id) }}">@csrf<button class="secondary">Mark read</button></form>@else<p>Read</p>@endif</section>@empty<p>No delivered assessment notices.</p>@endforelse
@endsection
