@extends('layouts.access')
@section('title', $role->label().' workspace')
@section('intro', 'Your account is signed in with '.$role->label().' access.')
@section('content')
    <div class="card-heading"><p class="eyebrow">{{ $role->label() }} account</p><h2>Account access is ready</h2></div>
    <div class="notice-content">
        <p>Welcome{{ auth()->user()->display_name ? ', '.auth()->user()->display_name : '' }}.</p>
        <p>This page confirms access for your role. Device registration, collections, processing and other operational tools will be introduced in later phases.</p>
        <p>Your account does not yet have organization or facility permissions.</p>
        <form method="POST" action="{{ route('logout') }}">@csrf<button class="primary" type="submit">Sign out</button></form>
    </div>
@endsection
