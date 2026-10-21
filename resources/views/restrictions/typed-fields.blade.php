@if($c->fact_key==='discovery_supplement')
<p>Truthful on-site discovery supplement: every distinguished HDD/SSD must be included. Keep the same unit reference across proposals and retries. Unknown serials are permitted; private distinguishing evidence must still establish physical identity. Withdraw reservations first. No dispatch, loss, recipient outcome or closure is eligible.</p>
@if($retainedUnits)<p>The recorded unit count and references are retained from the previous finding. Re-enter private observations. Changed physical count or identity remains held; do not omit or replace a unit.</p>@else
<p>Choose the number of physically distinguished units before entering private values (changing this reloads the form):
@for($count=1;$count<=10;$count++) <a href="{{ route('restrictions.show',['case'=>$c->id,'action'=>'proposal','units'=>$count]) }}">{{ $count }} units</a> @endfor</p>@endif
<input type="hidden" name="details[count]" value="{{ $unitCount }}">
@for($index=0;$index<$unitCount;$index++)
<fieldset><legend>Distinguished unit {{ $index+1 }}</legend>
<x-field :name="'details.units.'.$index.'.id'" label="Stable opaque unit reference (UUID, retain for later proposals)" :value="$retainedUnits[$index]['id'] ?? (string) \Illuminate\Support\Str::uuid()" />
<x-select-field :name="'details.units.'.$index.'.parent_id'" label="Evidenced original source member" :value="$retainedUnits[$index]['parent_id'] ?? ''" :options="$parents->pluck('public_code','subject_id')->all()" />
<x-select-field :name="'details.units.'.$index.'.media_kind'" label="Actual supported medium" :value="$retainedUnits[$index]['media_kind'] ?? ''" :options="['hdd'=>'HDD','ssd'=>'SSD']" />
<x-field :name="'details.units.'.$index.'.existing_code'" label="Existing canonical QR/manual code, if independently recognized" :required="false" />
<x-field :name="'details.units.'.$index.'.identifier'" label="Serial for a genuinely new identity only (optional/unknown)" :required="false" maxlength="100" />
<x-field :name="'details.units.'.$index.'.model'" label="Model for a genuinely new identity only (optional)" :required="false" maxlength="120" />
<x-select-field :name="'details.units.'.$index.'.mode'" label="Evidenced mass accounting mode" :options="['unaccounted'=>'Previously unaccounted output','reclassified'=>'Already included in an on-site aggregate — paired remeasurement']" />
<x-select-field :name="'details.units.'.$index.'.source_output_id'" label="Existing aggregate to remeasure (reclassification only)" :required="false" :options="$batchOutputs->where('type','!=','storage')->pluck('public_code','subject_id')->all()" />
<p>For previously unaccounted output, leave all measurement fields empty if mass is unknown. Reclassification requires both actual measurements. No balancing mass or new input is created.</p>
@foreach(['measurement'=>'Separate drive','remaining'=>'Remaining aggregate (reclassification only)'] as $measurement=>$label)
<fieldset><legend>{{ $label }} · kg</legend>
<x-select-field :name="'details.units.'.$index.'.'.$measurement.'.mode'" label="Measurement mode (leave empty for unknown/not applicable)" :required="false" :options="['net'=>'Measured net kg','gross_tare'=>'Measured gross minus tare kg']" />
@foreach(['net','gross','tare'] as $quantity)
<x-field :name="'details.units.'.$index.'.'.$measurement.'.'.$quantity" :label="ucfirst($quantity).' kg (up to 3 decimal places)'" :required="false" inputmode="decimal" />
@endforeach
<x-field :name="'details.units.'.$index.'.'.$measurement.'.source'" label="Private actual measurement provenance" :required="false" maxlength="1500" />
</fieldset>
@endforeach
</fieldset>
@endfor
@elseif($c->fact_key==='discovery_error')
<p>Prove the alleged extra medium was an erroneous observation or a duplicate of an already accounted canonical drive. No identity, output or quantity is created. A genuine discovery must use the supplement action.</p>
<fieldset><legend>Already accounted canonical media (duplicate-observation case only)</legend>
@foreach($batchOutputs->where('type','storage') as $output)<label><input type="checkbox" name="details[accounted_media][]" value="{{ $output->subject_id }}"> {{ $output->public_code }}</label>@endforeach
</fieldset>
@elseif($c->fact_key==='impact_continuity')
<p>Continuity permits fresh evidence only. After fresh prerequisites, submit new technical findings and use Verify fulfilled obligation. Original work, receipts, stock and completed history remain. New work, treatment and movement remain blocked while any obligation is pending.</p>
@endif
