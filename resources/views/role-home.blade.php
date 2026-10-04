@extends('layouts.access')
@section('title', $role->label().' workspace')
@section('intro', 'Your account is signed in with '.$role->label().' access.')
@section('content')
    <div class="card-heading"><p class="eyebrow">{{ $role->label() }} account</p><h2>Account access is ready</h2></div>
    <div class="notice-content">
        <p>Welcome{{ auth()->user()->display_name ? ', '.auth()->user()->display_name : '' }}.</p>
        @if($role === \App\Enums\Role::Consumer)
            <p>Register and view your own devices, retained self-report revisions and private identity labels.</p>
            <p><a class="primary button-link" href="{{ route('items.index') }}">My devices</a></p>
        @else
            <p>This page confirms access for your role. Assigned operational tools will be introduced in later phases.</p>
        @endif
        <p>Your account does not yet have organization or facility permissions.</p>
        <form method="POST" action="{{ route('logout') }}">@csrf<button class="primary" type="submit">Sign out</button></form>
    </div>
@endsection
