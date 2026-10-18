@extends('layouts.devices')
@section('title','Dismantling / Batches')
@section('intro','Record externally performed dismantling and on-site accounting. No downstream delivery or completed recycling is recorded here.')
@section('content')
<p><a class="button-link" href="{{ route('batches.create') }}">Prepare batch draft</a></p>
<section class="card notice-content"><h2>Find batch or output label</h2><form method="GET" action="{{ route('batches.lookup') }}"><x-field name="code" label="Permanent manual label code" maxlength="60" /><button type="submit">Find batch or output</button></form></section>
<section class="card notice-content"><h2>Your facility batches</h2>
@forelse($rows as $r)<article class="history-entry"><h3><a href="{{ route('batches.show',$r->subject_id) }}">{{ $r->public_code }}</a></h3><p>{{ $r->state==='draft' ? 'Draft — no stock claim or authority to dismantle' : 'Actual dismantling recorded — on-site accounting remains open' }}</p></article>@empty<p>No batch drafts or recorded dismantling at this facility.</p>@endforelse
</section>
@endsection
