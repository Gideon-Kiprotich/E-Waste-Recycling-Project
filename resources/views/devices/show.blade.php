@extends('layouts.devices')
@section('title', 'Device detail')
@section('intro', 'Your permanent identity and retained consumer self-report.')
@section('content')
    @if($duplicate)<x-feedback id="duplicate-warning" type="info" message="Possible duplicate: a known identifier matches a recorded identifier. This is only a warning; your registration is retained. Check that you have not registered the same physical item twice." />@endif
    <div class="workspace">
        <section class="card notice-content">
            <p class="eyebrow">{{ $item->category->label }}{{ $item->subtype ? ' · '.$item->subtype : '' }}</p><h2>{{ $item->model ?? 'Model unknown' }}</h2>
            <p class="manual-code">{{ $item->subject->public_code }}</p>
            <dl class="facts"><dt>State</dt><dd>{{ ucfirst($item->processing_state) }}</dd><dt>Initial custody</dt><dd>With you at registration</dd><dt>Registered</dt><dd>{{ $item->subject->created_at->format('j M Y H:i') }} UTC</dd><dt>Serial / IMEI</dt><dd>{{ $item->identifier_ciphertext ? 'Private identifier recorded' : 'Unknown / not provided' }}</dd></dl>
            <h3>Current consumer self-report · revision {{ $current->revision }}</h3><p class="hint">Not technician inspection or an approved processing recommendation.</p>
            @include('devices.report', ['observation'=>$current])
            @if($current->answers['safety_concern'] !== 'NO')<p class="form-end">Safety information needs review. Registration does not approve collection or treatment.</p>@endif
            @if($item->processing_state === 'registered')<a class="secondary button-link" href="{{ route('items.edit', $item->subject_id) }}">Update self-report</a>@endif
            @if($photo)<h3 class="section-title">Private device photo</h3><img class="device-photo" src="{{ route('items.photo', [$item->subject_id, $photo]) }}" alt="Your uploaded device photo">@endif
        </section>
        <aside class="card guidance"><h2>Permanent identity label</h2><img class="qr-image" src="{{ route('items.qr', $item->subject_id) }}" alt="QR lookup reference for this device"><p class="manual-code">{{ $item->subject->public_code }}</p><p>The code opens an authenticated lookup. It contains no personal details and grants no access by itself.</p><div class="actions"><a class="secondary button-link" href="{{ route('items.label', $item->subject_id) }}">Printable label</a><a class="secondary button-link" href="{{ route('items.qr', ['item'=>$item->subject_id, 'download'=>1]) }}">Download QR PNG</a></div></aside>
    </div>
    <section class="card notice-content section-title"><h2>Registration and observation history</h2><p>Only your registration and self-report revisions are recorded here.</p>
        <ol class="timeline">@foreach($history as $observation)<li><h3>{{ $observation->revision === 1 ? 'Registered · initial self-report' : 'Self-report revised' }} · revision {{ $observation->revision }}</h3><p class="hint">{{ $observation->observed_at->format('j M Y H:i') }} UTC · Consumer self-report</p>@include('devices.report')</li>@endforeach</ol>
        {{ $history->links('devices.pagination') }}
    </section>
@endsection
