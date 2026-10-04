@extends('layouts.access')
@section('title', 'Create a Consumer account')
@section('intro', 'Register with your email. Staff accounts are created by an administrator.')
@section('content')
    <div class="card-heading"><p class="eyebrow">Consumer registration</p><h2>Your account details</h2></div>
    <form class="account-form" method="POST" action="{{ route('register') }}">
        @csrf
        <x-field name="display_name" label="Display name" autocomplete="nickname" :required="false" maxlength="100" />
        <x-field name="email" label="Email address" type="email" autocomplete="username" maxlength="254" />
        <x-field name="password" label="Password" type="password" autocomplete="new-password" maxlength="128" hint="Use 12–128 characters. Choose a password you do not use elsewhere." />
        <x-field name="password_confirmation" label="Confirm password" type="password" autocomplete="new-password" maxlength="128" />
        <input type="hidden" name="notice_version" value="{{ $noticeVersion }}">
        <div class="acknowledgment">
            <input id="notice_acknowledged" name="notice_acknowledged" type="checkbox" value="1" required
                @if(old('notice_acknowledged')) checked @endif @error('notice_acknowledged') aria-invalid="true" aria-describedby="notice_acknowledged-error" @enderror>
            <label for="notice_acknowledged">I have read the <a href="{{ route('privacy.notice') }}" target="_blank" rel="noopener">privacy notice (opens a new tab)</a>, version {{ $noticeVersion }}.</label>
        </div>
        @error('notice_acknowledged')<p class="field-error" id="notice_acknowledged-error">{{ $message }}</p>@enderror
        <button class="primary" type="submit">Create Consumer account →</button>
        <p class="form-end">Already registered? <a href="{{ route('login') }}">Sign in</a></p>
    </form>
@endsection
