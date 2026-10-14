<ul>
@forelse($reasons as $reason)
    <li><strong>{{ $reason['kind'] }}:</strong> {{ $reason['message'] }}
        @if($reason['directory'])<a href="{{ route('admin.directory.index') }}">Resolve in Operators and places</a>@endif
    </li>
@empty
    <li>No current eligibility restriction recorded. A current Ready review is still required; this is not safety certification.</li>
@endforelse
</ul>
