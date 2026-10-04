@extends('layouts.access')
@section('title', 'Choose a new password')
@section('intro', 'Reset your password using the link from your recovery message.')
@section('content')
    <div class="card-heading"><p class="eyebrow">Password recovery</p><h2>Update your password</h2></div>
    <form class="account-form" method="POST" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <x-field name="email" label="Email address" type="email" :value="$email" autocomplete="username" maxlength="254" />
        <x-field name="password" label="New password" type="password" autocomplete="new-password" maxlength="128" hint="Use 12–128 characters. All existing signed-in sessions will be revoked." />
        <x-field name="password_confirmation" label="Confirm new password" type="password" autocomplete="new-password" maxlength="128" />
        <button class="primary" type="submit">Update password →</button>
        <p class="form-end"><a href="{{ route('password.request') }}">Request another reset link</a></p>
    </form>
@endsection
