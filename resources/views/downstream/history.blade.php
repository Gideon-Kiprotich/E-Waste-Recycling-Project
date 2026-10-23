<article class="history-entry">
    <h3>{{ \App\Support\Downstream\Context::LABELS[$r->kind] }} · {{ $r->kg ?? 'No quantity deduction' }}{{ $r->kg!==null?' kg':'' }}</h3>
    @if(isset($r->effective_kg) && (string)$r->effective_kg!==(string)$r->kg)
        <p>Effective quantity after reviewed correction: {{ $r->effective_kg }} kg. Original quantity above is retained.</p>
    @endif
    @if(isset($r->remaining_kg))
        <p>Unaccounted quantity on this {{ $r->kind }}: {{ $r->remaining_kg }} kg.</p>
    @endif
    <p>Observed <x-instant :value="$r->observed_at" /> · Recorded <x-instant :value="$r->recorded_at" />.</p>
    <p>{{ \Illuminate\Support\Facades\Crypt::decryptString($r->reason_ciphertext) }}</p>
    @if($r->condition)
        <p>Physically accepted condition: {{ $r->condition }}.</p>
    @endif
    @if($r->outcome)
        <p>Attested outcome: {{ $r->outcome }} · {{ \Illuminate\Support\Facades\Crypt::decryptString($r->issuer_ciphertext) }}.</p>
    @endif
    @if($r->attachment_id)
        <a href="{{ route('downstream.evidence',$r->id) }}">Download private movement evidence</a>
    @else
        @php($ref=json_decode(\Illuminate\Support\Facades\Crypt::decryptString($r->reference_ciphertext),true,flags:JSON_THROW_ON_ERROR))
        <p>Private reference: {{ $ref['issuer'] }} · {{ $ref['code'] }}. {{ $ref['provenance'] }}</p>
    @endif
    @foreach($r->reviews ?? [] as $revision)
        <p>Reviewed {{ str_replace('_',' ',$revision->fact) }}: {{ $revision->value }} · revision {{ $revision->version }} · Case reference {{ $revision->case_id }}. Case access requires an explicit investigation grant.</p>
    @endforeach
</article>
