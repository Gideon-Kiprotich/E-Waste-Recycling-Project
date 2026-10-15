@extends('layouts.devices')
@section('section-label','Collector · Routing')
@section('title','Approved routes')
@section('intro','Only your current approved ordered stops. Geometric pickup plans do not confirm appointments, receipt, transport or custody.')
@section('content')
<section class="card guidance"><ul>@forelse($routes as $route)<li><a href="{{ route('collector.routes.show',$route->id) }}">Open ordered stops</a> · <span class="manual-code">{{ $route->id }}</span></li>@empty<li>No current approved routes. Accepted jobs alone are not an approved route. Ask the Administrator to compare and approve eligible work; changed eligibility requires fresh review/acknowledgment.</li>@endforelse</ul></section>
@endsection
