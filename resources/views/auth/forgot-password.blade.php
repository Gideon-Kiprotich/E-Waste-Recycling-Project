@extends('layouts.access')
@section('title', 'Recover your account')
@section('intro', 'Request a private link to choose a new password.')
@section('content')
    <div class="card-heading"><p class="eyebrow">Password recovery</p><h2>Request a reset link</h2></div>
    <form class="account-form" method="POST" action="{{ route('password.email') }}">
        @csrf
        <p>Enter your account email. For privacy, we do not confirm whether an email is registered.</p>
        <x-field name="email" label="Email address" type="email" autocomplete="username" maxlength="254" />
        <button class="primary" type="submit">Request reset link →</button>
        <p class="form-end"><a href="{{ route('login') }}">Return to sign in</a></p>
    </form>
@endsection
