@extends('layouts.access')
@section('title', 'Sign in to your account')
@section('intro', 'Access your permitted workspace with your email and password.')
@section('content')
    <div class="card-heading"><p class="eyebrow">Account access</p><h2>Welcome back</h2></div>
    <form class="account-form" method="POST" action="{{ route('login') }}">
        @csrf
        <x-field name="email" label="Email address" type="email" autocomplete="username" maxlength="254" />
        <x-field name="password" label="Password" type="password" autocomplete="current-password" maxlength="128" />
        <a class="form-link" href="{{ route('password.request') }}">Forgot your password?</a>
        <button class="primary" type="submit">Sign in →</button>
        <p class="form-end">New here? <a href="{{ route('register') }}">Create a Consumer account</a></p>
    </form>
@endsection
