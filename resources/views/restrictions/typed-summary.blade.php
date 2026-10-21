@php($effects=json_decode($r->details,true))
@if($c->fact_key==='discovery_supplement')
<p>Proposed distinguished units: {{ $effects['count'] ?? 'Unknown' }}. Frozen input membership and mass do not change.</p>
@foreach($effects['units'] ?? [] as $unit)
<dl class="facts"><dt>Unit reference</dt><dd>{{ $unit['id'] }}</dd><dt>Source member reference</dt><dd>{{ $unit['parent_id'] }}</dd><dt>Medium</dt><dd>{{ strtoupper($unit['media_kind']) }} · {{ empty($unit['existing_id']) ? 'New canonical facility discovery' : 'Recognized existing identity; no origin or custody change' }}</dd><dt>Accounting mode</dt><dd>{{ str_replace('_',' ',$unit['mode']) }}</dd><dt>Drive net kg</dt><dd>{{ $unit['measurement']['net_kg'] ?? 'Unknown — measurements pending' }}</dd>
@if($unit['mode']==='reclassified')<dt>Aggregate reference</dt><dd>{{ $unit['source_output_id'] }}</dd><dt>Remaining aggregate net kg</dt><dd>{{ $unit['remaining']['net_kg'] }}</dd>@endif</dl>
@endforeach
<p>Private serials and measurement evidence are excluded from this summary. Review the accountable identity/provenance basis and authorized evidence separately. Provenance does not establish completed accounting or treatment.</p>
@elseif($c->fact_key==='discovery_error')<p>Proposed effect: supersede only the proven erroneous additional-media assertion. No production, identity or measurement changes.</p>
@elseif($c->fact_key==='impact_continuity')<p>Proposed effect: exact continuity and subsequent evidence-backed fulfillment. No physical work or movement is asserted.</p>
@else<p>Proposed effect applies only to this controlled fact. Original history remains; fresh prerequisites and independent dependent-obligation reviews remain required.</p>@endif
