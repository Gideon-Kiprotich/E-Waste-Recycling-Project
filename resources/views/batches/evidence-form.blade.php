<x-field name="observed_at" type="datetime-local" label="Actual activity / measurement observation time (UTC)" :value="now('UTC')->format('Y-m-d\TH:i')" hint="UTC is three hours behind Nairobi. Explain retrospective entry privately. History shows Africa/Nairobi time." />
<x-select-field name="evidence_kind" label="Private evidence provenance" :options="['attachment'=>'Private JPEG/PNG/PDF','operator_reference'=>'Accountable record reference']" />
<x-field name="evidence" type="file" label="Private attachment (attachment option only)" :required="false" hint="JPEG/PNG/PDF up to 5 MiB; never upload user file contents." />
<x-field name="reference_issuer" label="Record issuer (reference option only)" :required="false" maxlength="120" />
<x-field name="reference_code" label="Private record number (reference option only)" :required="false" maxlength="120" />
<div class="acknowledgment"><input id="reference_attested" type="checkbox" name="reference_attested" value="1" @if($errors->has('reference_attested')) aria-invalid="true" aria-describedby="reference_attested-error" @endif><label for="reference_attested">I am accountable for this reference; it is not independent certification.</label></div>
@error('reference_attested')<p class="field-error" id="reference_attested-error">{{ $message }}</p>@enderror
<x-field name="reason" label="Private activity / correction reason and evidence explanation" maxlength="2000" />
