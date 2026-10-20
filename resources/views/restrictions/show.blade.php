@extends('layouts.devices')
@section('section-label','Operational restriction review')
@section('title','Restriction investigation')
@section('intro','Recording-error investigation is separate from hazard remediation, physical receipt and professional safety certification.')
@section('content')
<section class="card notice-content"><h2>{{ $c->public_code }} · {{ str_replace('_',' ',$c->state) }}</h2><p>{{ ucfirst($c->source_kind) }} / {{ str_replace('_',' ',$c->fact_key) }} · Original fact: <strong>{{ $c->original_value }}</strong> · source version {{ $c->source_version }} · restriction version {{ $epoch }}</p>
<p>Source record {{ $c->source_id }} · source event {{ $c->source_event_id }}</p>
@include('restrictions.source-facts')
@if($admin)<p><a href="{{ route('restrictions.source',[$c->source_kind,$c->source_id,$c->fact_key]) }}">View original source fact</a></p>@endif
@if($admin && $source->request_id)<p><a href="{{ route('admin.reviews.show',$source->request_id) }}">Open Request review</a> after approved supersession; old Ready reviews and allocations do not revive.</p>@endif
@if(!$c->supported)<p>Resolution unavailable for this exception type. Use investigation notes and accountable assignment; no correction, custody or accounting bypass is offered.</p>@endif
@if($c->state==='closed_no_change')<p>Investigation closed without changing the restriction.</p>@elseif($c->state==='superseded')<p>The specific recorded fact was superseded; fresh review is required. Independent restrictions and completed-dependent exceptions remain blocking.</p>@else<p>The restriction remains effective during investigation. Only known, evidenced errors about the same physical subject may be proposed; actual remediation or changed identity/configuration requires a different supported workflow.</p>@endif
@if($c->pre_receipt && !$admin)<p>Investigation-only grant: this case and subject only. No general item search, private identifier, owner contact, receipt, assessment or custody authority is granted.</p>@endif
@if($source->source_attachment_id && ($admin || in_array($source->source_attachment_id,$allowedEvidence,true)))<p><a href="{{ route('restrictions.evidence',[$c->id,$source->source_attachment_id]) }}">Download authorized source evidence</a></p>@endif
@include('restrictions.impact')
@if($c->active)<nav aria-label="Investigation actions"><div class="actions">@foreach($allowed as $action)<a class="secondary button-link" href="{{ route('restrictions.show',['case'=>$c->id,'action'=>$action]) }}">{{ \App\Support\Restrictions\Context::LABELS[$action] }}</a>@endforeach</div></nav>@endif
@if($mode!=='history')@include('restrictions.form')@endif
</section>
<section class="card notice-content section-title"><h2>Original facts, findings and review history</h2><p>Original evidence and observations are retained. Recorded assertions are not independent certification.</p>
@foreach($history as $r)<article><h3>{{ $r->version }} · {{ \App\Support\Restrictions\Context::LABELS[$r->kind] }}</h3>@if($admin)<p>Recorded by {{ $r->actor_name ?: 'Accountable staff account' }} · account {{ $r->actor_id }}</p>@endif<p>Observed {{ \Carbon\CarbonImmutable::parse($r->observed_at)->timezone('Africa/Nairobi')->format('j M Y H:i T') }} · recorded {{ \Carbon\CarbonImmutable::parse($r->recorded_at)->timezone('Africa/Nairobi')->format('j M Y H:i T') }}</p>@if($admin || $r->actor_id===auth()->id())<p>{{ \Illuminate\Support\Facades\Crypt::decryptString($r->reason_ciphertext) }}</p>@endif
@if($r->kind==='proposal')<dl class="facts"><dt>Original controlled fact</dt><dd>{{ $r->original_value }}</dd><dt>Proposed fact</dt><dd>{{ $r->proposed_value }}</dd>@if($admin || $r->actor_id===auth()->id())<dt>Same-subject basis</dt><dd>{{ \Illuminate\Support\Facades\Crypt::decryptString($r->basis_ciphertext) }}</dd>@endif</dl><p>Unknown findings or a non-YES attestation cannot be approved.</p>@endif
@if($r->proposal_id)<p>Independent review references proposal {{ $r->proposal_id }}.</p>@endif
@if($r->attachment_id && ($admin || $r->actor_id===auth()->id()))<p><a href="{{ route('restrictions.evidence',[$c->id,$r->attachment_id]) }}">Download private case evidence</a></p>@elseif($r->reference_ciphertext && ($admin || $r->actor_id===auth()->id()))<p>Accountable private evidence reference: {{ \Illuminate\Support\Facades\Crypt::decryptString($r->reference_ciphertext) }}</p>@endif
</article>@endforeach
{{ $history->links() }}</section>
<p><a href="{{ route('restrictions.show',$c->id) }}">Reload current investigation</a> · <a href="{{ route('restrictions.index') }}">Operational restriction review</a></p>
@endsection
