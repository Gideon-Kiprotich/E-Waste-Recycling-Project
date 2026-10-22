@php($details=json_decode($r->details,true))
<p>Typed downstream findings; original history remains. Responsibility, physical acknowledgment, disposition and completion are separate.</p>
@foreach($details['revisions'] ?? [] as $revision)<dl class="facts"><dt>Exact fact reference</dt><dd>{{ $revision['anchor_kind'] }} · {{ $revision['anchor_id'] }} · {{ $revision['fact'] }}</dd><dt>Expected revision</dt><dd>{{ $revision['expected_version'] }}</dd><dt>Proposed effective value</dt><dd>{{ $revision['value'] }}</dd></dl>@endforeach
<p>Linked findings: {{ count($details['party_findings'] ?? []) }}. Review private provenance separately; no physical outcome follows from approval.</p>
