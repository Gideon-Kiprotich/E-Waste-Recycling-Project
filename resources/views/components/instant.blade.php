@props(['value'])
@if($value)<time datetime="{{ \Carbon\CarbonImmutable::parse($value)->utc()->toIso8601String() }}">{{ \App\Support\NairobiTime::display($value) }}</time>@else<span>Not recorded</span>@endif
