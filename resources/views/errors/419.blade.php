@extends('layouts.access')
@section('title', 'Your form session expired')
@section('intro', 'Please open the form again and retry. Your password has not been saved.')
@section('content')
    <div class="notice-content"><h2>Start again securely</h2><a class="primary button-link" href="{{ route('login') }}">Return to sign in →</a></div>
@endsection
