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
            <p><a href="{{ route('requests.index') }}">My requests</a></p>
            @foreach($consumerAssignments as $assignment)<p><a href="{{ route('requests.show',$assignment->request_id) }}">{{ $assignment->public_code }}</a> · {{ app(\App\Support\Assignments\Eligibility::class)->current($assignment) ? \App\Support\Assignments\History::LABELS[$assignment->state] : 'Assignment changed — fresh allocation required' }}</p>@endforeach
        @elseif($role === \App\Enums\Role::Administrator)
            <p><a class="primary button-link" href="{{ route('admin.directory.index') }}">Operators and places</a> <a class="secondary button-link" href="{{ route('admin.reviews.index') }}">Request review</a> <a class="secondary button-link" href="{{ route('admin.assignments.index') }}">Pickup allocation</a> <a class="secondary button-link" href="{{ route('admin.collectors.index') }}">Collector access</a> <a class="secondary button-link" href="{{ route('admin.point-staff') }}">Place staff access</a> <a class="secondary button-link" href="{{ route('admin.routing.index') }}">Route planning</a></p>
        @elseif($role === \App\Enums\Role::Processor)
            <p><a class="primary button-link" href="{{ route('processor.intake') }}">Point intake</a>. An Administrator must explicitly grant point scope before drop-off receipt.</p><p><a href="{{ route('assessments.index') }}">Assessments / Inspection</a> requires explicit facility scope and accepted facility custody.</p><p><a href="{{ route('sanitization.index') }}">Media sanitization</a> records external standalone HDD/SSD work and separate assurance; no hardware is accessed.</p>
        @endif
        @if($role === \App\Enums\Role::Collector)<p><a class="primary button-link" href="{{ route('collector.assignments.index') }}">Offers and accepted work</a></p><p><a href="{{ route('collector.routes.index') }}">Approved routes</a></p><p>Operator scope must be granted by an Administrator; acceptance is not scheduling.</p>@endif
        @if($role !== \App\Enums\Role::Consumer)<p><a href="{{ route('handovers.index') }}">Custody handovers</a> · <a href="{{ route('handovers.notices') }}">Handover notices</a></p>@endif
        <p><a href="{{ route('assignment.notices') }}">Assignment notices</a></p>
        <form method="POST" action="{{ route('logout') }}">@csrf<button class="primary" type="submit">Sign out</button></form>
    </div>
@endsection
