@extends('layouts.devices')
@section('title',$private ? 'Media inventory' : 'Your media coverage')
@section('section-label','S10/S11 · Observed inventory and attested coverage')
@section('intro','Inventory is an observation, not physical detachment, treatment, certification or release. No hardware or user files are accessed by this application.')
@section('content')
<p>{{ $row->public_code }}</p>
<section class="card notice-content"><h2>Review coverage</h2><p><strong>{{ ['blocked'=>'Coverage blocked','not_applicable_attested'=>'Not applicable (attested)','satisfied_attested'=>'Data-treatment coverage satisfied (attested)'][$coverage['status']] }}</strong></p>
@foreach(array_unique($coverage['reasons']) as $reason)<p>{{ $reason }}</p>@endforeach
@if($coverage['destruction_concern'])<x-feedback id="inventory-destruction" type="error" message="Destruction reported — parent must not be presented as reusable. A disputed relationship cannot erase this concern; supported physical configuration is required in a later workflow." />@endif
<p>Coverage never authorizes reuse or release. Other nonvolatile storage has inventory-only support.</p></section>
@if($private)
<p><a href="{{ route('assessments.show',$row->subject_id) }}">Assessments / Inspection</a> · <a href="{{ route('handovers.index') }}">Custody handovers</a></p>
<p>First establish accepted compatible facility custody and a current non-HOLD parent decision. Record every medium and uncertain area, then <strong>Attest completeness</strong>, <strong>Authorize media treatment</strong> for each derived HDD/SSD, and use its existing assurance history. Existing standalone media retains its own inspection/decision. Exact HDD/SSD facility capabilities are required; ask an Administrator to review <strong>Operators and places</strong> if absent.</p>
<p>Any new inventory revision needs a fresh completeness attestation and authorizations; accepted older treatment needs legitimate applicability review, not invented repeated work. Inventory omissions never remove a medium. Disputed canonical identity stays claimed and blocked pending a separately reviewed correction workflow. Independent parent/media transfer and replacement remain unsupported. Permitted canonical detachment uses the separate RECYCLE Dismantling / Batches workflow, never an inventory omission.</p>
@foreach($blockers as $blocker)<x-feedback type="info" :message="$blocker" :id="'inventory-blocker-'.$loop->index" />@endforeach
@if(!$blockers)
<nav class="device-nav" aria-label="Media inventory actions">@foreach(['identify'=>'Record inventory observation','link'=>'Recognize existing medium','unresolved'=>'Record unresolved inventory','correct'=>'Correct inventory entry','attest'=>'Attest completeness','authorize'=>'Authorize media treatment'] as $action=>$label)<a href="{{ route('media-inventory.show',['subject'=>$row->subject_id,'action'=>$action]) }}">{{ $label }}</a>@endforeach</nav>
@endif
<section class="card notice-content"><h2>Retained observed inventory</h2>@if($row->processing_state==='dismantled')<p>This is historical embedding evidence. Actual batch detachment ended active embedding; current batch-controlled treatment uses its separate authorization context.</p>@endif<p>Inventory version {{ $version }}. Unknown serials are valid; never guess a serial, count or physical match.</p>
@forelse($entries as $entry)<article class="section-title"><h3>{{ $entry->public_code ?? 'Unbound observation' }} · {{ $entry->state }}</h3><p>Entry {{ $entry->id }} · {{ $entry->media_kind ?? 'Physical identity unresolved' }}</p><p>{{ \Illuminate\Support\Facades\Crypt::decryptString($entry->placement_ciphertext) }}</p>
@if($entry->medium_id)<p><a href="{{ route('media-inventory.label',$entry->medium_id) }}">Printable media label</a></p>@endif
@if($entry->medium_id && $entry->media_kind !== 'other_nonvolatile')<p><a href="{{ route('sanitization.show',$entry->medium_id) }}">Open media assurance and history</a></p>@elseif($entry->media_kind === 'other_nonvolatile')<p><strong>Treatment support unavailable.</strong> No HDD/SSD technique eligibility is implied.</p>@endif</article>@empty<p>No observations yet. An empty list does not establish N/A.</p>@endforelse</section>
@if($mode === 'authorize' && !$selectedMedium && !$blockers)
<section class="card notice-content"><h2>Choose a derived HDD/SSD</h2>
@foreach($entries->filter(fn($e)=>$e->state==='confirmed' && in_array($e->media_kind,['hdd','ssd'],true) && \Illuminate\Support\Facades\DB::table('media_origins')->where('subject_id',$e->medium_id)->exists()) as $e)<p><a href="{{ route('media-inventory.show',['subject'=>$row->subject_id,'action'=>'authorize','medium'=>$e->medium_id]) }}">Authorize {{ $e->public_code }} · {{ strtoupper($e->media_kind) }}</a></p>@endforeach
<p>Other media is inventory-only. Existing standalone drives use their own inspection and decision.</p></section>
@endif
@if($mode && !$blockers && ($mode !== 'authorize' || $selectedMedium))
<section class="card"><h2>{{ ['identify'=>'Record inventory observation','link'=>'Recognize existing medium','unresolved'=>'Record unresolved inventory','correct'=>'Correct inventory entry','attest'=>'Attest completeness','authorize'=>'Authorize media treatment'][$mode] }}</h2><p>Private text, identifiers, references and files must be re-entered after errors. Preserve uncertainty; do not power, dismantle or inspect user files for this form.</p>
<form class="account-form" method="POST" enctype="multipart/form-data" action="{{ route('media-inventory.store',['subject'=>$row->subject_id,'kind'=>$mode]) }}">@csrf
<input type="hidden" name="idempotency_key" value="{{ old('idempotency_key',\Illuminate\Support\Str::uuid()) }}"><input type="hidden" name="expected_version" value="{{ $version }}"><input type="hidden" name="expected_context" value="{{ $token }}">
@if(in_array($mode,['identify','link','correct'],true))
<x-select-field name="entry_id" label="Existing inventory entry" :required="$mode === 'correct'" :options="$entries->filter(fn($e)=>$mode === 'correct' || $e->state === 'unresolved')->mapWithKeys(fn($e)=>[$e->id=>$e->id.' · '.$e->state])->all()" hint="Leave blank for a new observation. Resolve an unbound uncertain entry using its stable reference; bound identities cannot be replaced." />
@endif
@if($mode==='identify')
<x-select-field name="media_kind" label="Confirmed physical media type" :options="['hdd'=>'HDD','ssd'=>'SSD','other_nonvolatile'=>'Other nonvolatile storage — inventory only']" />
<x-field name="model" label="Observed model (optional)" :required="false" maxlength="120" /><x-field name="identifier" label="Private serial (optional)" :required="false" maxlength="100" hint="Leave unknown blank. Do not copy the parent serial. Global serial uniqueness applies." />
@elseif($mode==='link')
<x-select-field name="existing_code" label="Known medium label / manual code" :options="$candidates->mapWithKeys(fn($e)=>[$e->public_code=>$e->public_code.' · '.strtoupper($e->subtype).' · '.($e->model ?? 'Model unknown')])->all()" />
@if($candidates->isEmpty())<p>No unclaimed standalone HDD/SSD is available under this parent owner and current facility. Resolve legitimate receipt/access setup; a serial match grants no adoption rights. Use an unresolved observation if matching is uncertain.</p>@endif
@elseif($mode==='correct')
<x-select-field name="entry_state" label="Corrected observation state" :options="['disputed'=>'Bound identity disputed — claim retained','unresolved'=>'Unbound observation unresolved','not_present'=>'Unbound observation not present — evidence required']" />
@elseif($mode==='attest')
<x-field name="na_justification" label="Explicit N/A justification (required for technician NO)" :required="false" maxlength="1500" hint="Explain the evidence establishing no applicable nonvolatile storage; an empty list alone is insufficient." />
<x-select-field name="completeness" label="Inventory completeness" :options="['YES'=>'Complete — every relevant area accounted for','NO'=>'Incomplete','UNKNOWN'=>'Unknown']" hint="Inaccessible areas/configuration storage uncertainty block YES. N/A additionally requires current technician NO and evidenced justification in the reason." />
@elseif($mode==='authorize')
<p>Selected {{ $selectedMedium->public_code }} · {{ strtoupper($selectedMedium->media_kind) }}. Current authorization version {{ $authorizationVersion }}.</p>
<input type="hidden" name="medium_id" value="{{ $selectedMedium->medium_id }}"><input type="hidden" name="expected_authorization_version" value="{{ $authorizationVersion }}">
@endif
@if(in_array($mode,['identify','link','unresolved','correct'],true))<x-field name="placement" label="Private distinguishing placement and observation" maxlength="1500" hint="Describe actual position and how this physical unit is distinguished; include access limits. Similar model/photos alone do not prove a match." />@endif
@foreach(\App\Support\MediaInventory\Context::checks($mode) as $key=>$label)<x-select-field :name="'checks.'.$key" :id="'checks_'.$key" :label="$label" :options="['YES'=>'Yes','NO'=>'No','UNKNOWN'=>'Unknown']" />@endforeach
<x-observation-time />
<x-select-field name="evidence_kind" label="Private evidence provenance" :options="['operator_reference'=>'Accountable operator reference','attachment'=>'Private JPEG/PNG/PDF attachment']" />
<x-field name="evidence" type="file" label="Private attachment (attachment option only)" :required="false" hint="One supported file up to 5 MiB; never include user file contents." />
<x-field name="reference_issuer" label="Record issuer (reference option only)" :required="false" maxlength="120" /><x-field name="reference_code" label="Record number (reference option only)" :required="false" maxlength="120" />
<div class="acknowledgment"><input id="reference_attested" type="checkbox" name="reference_attested" value="1" @if($errors->has('reference_attested')) aria-invalid="true" aria-describedby="reference_attested-error" @endif><label for="reference_attested">I am accountable for this reference; it is not independently verified.</label></div>@error('reference_attested')<p class="field-error" id="reference_attested-error">{{ $message }}</p>@enderror
<x-field name="reason" label="Private evidence, justification and correction reason" maxlength="2000" hint="For N/A explicitly justify why complete inventory establishes no applicable nonvolatile storage. For correction explain the mistaken observation without claiming physical removal." />
<button class="primary">{{ ['identify'=>'Record inventory observation','link'=>'Recognize existing medium','unresolved'=>'Record unresolved inventory','correct'=>'Correct inventory entry','attest'=>'Attest completeness','authorize'=>'Authorize media treatment'][$mode] }}</button>
</form></section>
@endif
<section class="card notice-content"><h2>Retained inventory and authorization history</h2>
@foreach(['inventory'=>$history,'authorization'=>$authorizations] as $kind=>$records)@foreach($records as $r)<article class="section-title"><h3>{{ $kind }} · version {{ $r->version }}@if($kind==='inventory') · {{ $r->kind }}@endif</h3><p>Observed <x-instant :value="$r->observed_at" /> · Recorded <x-instant :value="$r->recorded_at" />. Actor reference {{ $r->actor_id }}.</p><p>{{ \Illuminate\Support\Facades\Crypt::decryptString($r->reason_ciphertext) }}</p>@if($r->attachment_id)<p><a href="{{ route('media-inventory.evidence',['kind'=>$kind,'record'=>$r->id]) }}">Download private inventory evidence</a></p>@else
@php($ref=json_decode(\Illuminate\Support\Facades\Crypt::decryptString($r->reference_ciphertext),true,flags:JSON_THROW_ON_ERROR))<p>{{ $ref['issuer'] }} · {{ $ref['code'] }}. {{ $ref['provenance'] }}</p>@endif</article>@endforeach @endforeach
</section>
@endif
@endsection
