@extends('layouts.devices')
@section('title', 'My devices')
@section('intro', 'Register one supported device or standalone part at a time.')
@section('content')
    <div class="actions"><a class="primary button-link" href="{{ route('items.create') }}">Register a device or part</a></div>
    <section class="card notice-content">
        <h2>Find a label manually</h2>
        <form method="GET" action="{{ route('items.lookup.manual') }}" class="lookup-form">
            <x-field name="public_code" label="Manual device code" :maxlength="60" hint="Enter the complete EWC code. A label never grants access to someone else's device." />
            <button class="secondary" type="submit">Find my device</button>
        </form>
    </section>
    <section aria-label="Your devices and identified media" class="device-grid">
        @forelse($items as $item)
            <article class="card notice-content"><p class="eyebrow">{{ $item->category->label }}{{ $item->subtypeLabel() ? ' · '.$item->subtypeLabel() : '' }}</p>
                <h2><a href="{{ route('items.show', $item->subject_id) }}">{{ $item->model ?? 'Model unknown' }}</a></h2>
                <p class="manual-code">{{ $item->subject->public_code }}</p><p>State: {{ ucfirst($item->processing_state) }}</p><p class="hint">{{ $item->intake_class === 'processor_derived' ? 'Identified at facility' : 'Registered' }} <x-instant :value="$item->subject->created_at" /> · {{ $item->intake_class === 'processor_derived' ? 'Not a first receipt' : 'Self-report' }}</p>
            </article>
        @empty
            <div class="card notice-content"><h2>No devices registered yet</h2><p>Register one supported device or standalone part at a time. Unknown model or serial is welcome.</p><a href="{{ route('items.create') }}">Register your first device or part</a></div>
        @endforelse
    </section>
    {{ $items->links('devices.pagination') }}
@endsection
