<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="referrer" content="no-referrer">
    <title>@yield('title') · E-Waste Connect</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <a class="skip-link" href="#main">Skip to content</a>
    <div class="context-bar">Nairobi County · University study project</div>
    <header class="site-header">
        <a class="brand" href="{{ auth()->check() ? route('dashboard') : route('login') }}"><span class="brand-mark" aria-hidden="true">↻</span><span>E-Waste Connect<small>Nairobi study project</small></span></a>
        @auth<form method="POST" action="{{ route('logout') }}">@csrf<button class="secondary" type="submit">Sign out</button></form>@else<a class="secondary button-link" href="{{ route('login') }}">Sign in</a>@endauth
    </header>
    <main id="main" class="page" tabindex="-1">
        @if(auth()->user()?->role() === \App\Enums\Role::Consumer)<nav class="device-nav" aria-label="Consumer navigation"><a href="{{ route('consumer.home') }}">Overview</a><a href="{{ route('items.index') }}">My devices</a><a href="{{ route('items.create') }}">Register device or part</a><a href="{{ route('requests.index') }}">My requests</a><a href="{{ route('collection.points') }}">Collection points</a><a href="{{ route('privacy.notice') }}">Privacy notice</a></nav>@endif
        @if(auth()->user()?->role() === \App\Enums\Role::Administrator)<nav class="device-nav" aria-label="Administrator navigation"><a href="{{ route('admin.directory.index') }}">Operators and places</a><a href="{{ route('admin.reviews.index') }}">Request review</a><a href="{{ route('admin.assignments.index') }}">Pickup allocation</a><a href="{{ route('admin.collectors.index') }}">Collector access</a><a href="{{ route('admin.routing.index') }}">Route planning</a><a href="{{ route('privacy.notice') }}">Privacy notice</a></nav>@endif
        @if(auth()->user()?->role() === \App\Enums\Role::Collector)<nav class="device-nav" aria-label="Collector navigation"><a href="{{ route('collector.assignments.index') }}">Offers and accepted work</a><a href="{{ route('collector.routes.index') }}">Approved routes</a></nav>@endif
        @auth<p><a href="{{ route('assignment.notices') }}">Assignment notices</a></p>@endauth
        <div class="page-heading"><p class="eyebrow">@yield('section-label', 'Device registration and identity')</p><h1 id="registration-heading" tabindex="-1">@yield('title')</h1><p>@yield('intro')</p></div>
        <x-feedback-summary :field-ids="$fieldIds ?? []" fallback-id="registration-heading" />
        @yield('content')
    </main>
    <footer class="site-footer"><span>Nairobi County case study · Use synthetic information for evaluation</span><a href="{{ route('privacy.notice') }}">Privacy notice</a></footer>
</body>
</html>
