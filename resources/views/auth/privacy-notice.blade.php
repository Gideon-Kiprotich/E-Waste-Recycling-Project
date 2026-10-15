@extends('layouts.access')
@section('title', 'Account privacy notice')
@section('intro', 'Version '.$noticeVersion.' · Effective 15 October 2026')
@section('content')
    @include(App\Support\PrivacyNotice::VIEW)
@endsection
