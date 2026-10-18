@extends('layouts.devices')
@section('title','Prepare batch draft')
@section('intro','Selection prepares a record. It neither reserves inputs nor authorizes or performs physical dismantling.')
@section('content')
<section class="card notice-content"><h2>Select current facility inputs</h2><p>Only intact whole devices with complete separable HDD/SSD or evidenced empty inventory, and verified basic ICT fans can later qualify. Standalone-drive dismantling and unsupported media operations are excluded. Each input needs a current human RECYCLE decision. Resolve setup before recording actual work.</p>
<form method="POST" enctype="multipart/form-data" action="{{ route('batches.store',[$id,'draft']) }}">@csrf
<input type="hidden" name="idempotency_key" value="{{ old('idempotency_key',\Illuminate\Support\Str::uuid()) }}"><input type="hidden" name="expected_version" value="{{ $version }}"><input type="hidden" name="expected_context" value="{{ $token }}">
<fieldset id="inputs" tabindex="-1" @if($errors->has('inputs')) aria-invalid="true" aria-describedby="inputs-error" @endif><legend>Facility items (maximum 25)</legend>
@forelse($rows as $r)<div class="acknowledgment"><input id="input-{{ $r->subject_id }}" type="checkbox" name="inputs[]" value="{{ $r->subject_id }}"><label for="input-{{ $r->subject_id }}">{{ $r->public_code }} · {{ $r->category_label }} · {{ $r->model ?? '' }} · {{ $r->processing_state }}</label></div><p><a href="{{ route('assessments.show',$r->subject_id) }}">Review inspection and pathway</a></p>@empty<p>No facility-controlled inputs. Complete an actual accepted facility handover before using this workspace.</p>@endforelse
@error('inputs')<p class="field-error" id="inputs-error">{{ $message }}</p>@enderror</fieldset>
@include('batches.evidence-form')<button type="submit">Save draft selection</button></form></section>
@endsection
