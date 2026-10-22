<p>Record only this action's evidenced facts. Leaving a measurement value empty excludes that fact; every affected measurement/allocation must be included together. Unknown findings remain blocked. Private entries must be re-entered after errors.</p>
@if($downstreamForm['findings']->isNotEmpty())
<p>Use a linked party's current signed findings as a starting point (review before submitting):</p>
@foreach($downstreamForm['findings'] as $finding)<p><a href="{{ route('restrictions.show',['case'=>$c->id,'action'=>$mode,'finding'=>$finding->id]) }}">Use findings {{ $finding->version }} · {{ $finding->display_name }}</a></p>@endforeach
<fieldset id="details_party_findings" aria-invalid="{{ $errors->has('details.party_findings')?'true':'false' }}" @error('details.party_findings') aria-describedby="error-details_party_findings" @enderror><legend>Current linked findings relied upon</legend>
@foreach($downstreamForm['findings'] as $finding)<label><input type="checkbox" name="details[party_findings][]" value="{{ $finding->id }}"> Findings {{ $finding->version }} · {{ $finding->display_name }}</label>@endforeach
@error('details.party_findings')<p class="field-error" id="error-details_party_findings">{{ $message }}</p>@enderror</fieldset>
@endif
@foreach($downstreamForm['rows'] as $index=>$row)
<fieldset><legend>{{ $row['label'] }}</legend><p>Original: {{ $row['original'] ?? 'Not recorded' }} · Effective: {{ $row['effective'] ?? 'Not recorded' }} · revision {{ $row['expected_version'] }}.</p>
@foreach(['anchor_kind','anchor_id','fact','expected_version','predecessor_id'] as $key)<input type="hidden" name="details[revisions][{{ $index }}][{{ $key }}]" value="{{ $row[$key] }}">@endforeach
<x-field :name="'details.revisions.'.$index.'.value'" label="Proposed effective value (empty means no change)" :required="false" :value="$row['value']" autocomplete="off" />
</fieldset>
@endforeach
@if($c->fact_key==='downstream_measurement')<p>All values are kg with at most three decimal places. Include the complete proved vector, including consumed reservation amounts when the original dispatch measurement was wrong. Withdraw outstanding reservations first. Upward available stock needs evidence of the same original production physically present and unallocated. Honest uncertainty or inconsistency stays pending; no balancing entries are permitted. After approval, review exact continuity and record fresh complete accounting.</p>@endif
@if(in_array($c->fact_key,['downstream_report_error','downstream_located','downstream_loss_error'],true))<p>To retire the whole claim, keep its retire value 0 and leave remaining kg empty. For a partial aggregate correction, clear the retire value and enter only the true remaining disputed/lost kg. Do not submit both. Canonical drives remain indivisible.</p>@endif
@if($c->fact_key==='downstream_recipient')
<x-select-field name="details.receiver_actor_id" label="Proposed named Processor at the same destination" :options="$downstreamForm['recipientCandidates']->pluck('display_name','id')->all()" />
<x-select-field name="details.profile_record_id" label="Current review in the original destination/profile namespace" :options="$downstreamForm['recipientProfiles']->pluck('material_key','id')->all()" />
<p>Withdraw undispatched remainder first. Approval ends obsolete authority and proposes a successor; the successor must accept responsibility, then personally acknowledge any outstanding physical receipt. Existing valid receipt is retained. No destination change or new custody is inferred.</p>
@endif
@foreach(\App\Support\Restrictions\DownstreamReview::FACTS as $fact)
<x-select-field :name="'details.facts.'.$fact" :label="ucfirst(str_replace('_',' ',$fact)).' — attest only what you can evidence in your facility scope'" :value="$downstreamForm['details']['facts'][$fact] ?? 'UNKNOWN'" :options="['UNKNOWN'=>'Unknown / not established here','NO'=>'No','YES'=>'Yes — supported by evidence']" />
@endforeach
