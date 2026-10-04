@extends('layouts.access')
@section('title', 'Access is restricted')
@section('intro', 'This page is not available for your role.')
@section('content')
    <div class="notice-content"><h2>Return to your workspace</h2><p>Your permitted workspace is available from the link below.</p><a class="primary button-link" href="{{ route('dashboard') }}">My workspace →</a></div>
@endsection
