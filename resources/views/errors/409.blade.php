@extends('layouts.devices')
@section('title', 'Submission conflict')
@section('intro', 'Your existing device and history are preserved.')
@section('content')
<x-feedback type="error" title="Reload before submitting" :message="$exception->getMessage() ?: 'This submission conflicts with an earlier change.'" :focus="true" />
<a class="secondary button-link" href="{{ route('items.index') }}">Back to My devices</a>
@endsection
