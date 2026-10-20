@extends('layouts.devices')
@section('section-label','Operational restriction review')
@section('title','Restriction review notices')
@section('intro','Recipient-scoped in-app updates. A notice is not a physical acknowledgment or delivery to an email inbox.')
@section('content')
<section class="card notice-content"><h2>Your notices</h2>@forelse($rows as $n)<p>Restriction investigation updated. <a href="{{ route('restrictions.show',$n->case_id) }}">Open currently authorized case</a> · case access may have ended.</p>@if(!$n->read_at)<form method="POST" action="{{ route('restrictions.notices.read',$n->id) }}">@csrf<button type="submit" class="secondary">Mark read</button></form>@else<p>Read</p>@endif @empty<p>No delivered restriction review notices.</p>@endforelse{{ $rows->links() }}</section>
@endsection
