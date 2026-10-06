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
        <a class="brand" href="{{ route('items.index') }}"><span class="brand-mark" aria-hidden="true">↻</span><span>E-Waste Connect<small>Consumer devices · Nairobi</small></span></a>
        <form method="POST" action="{{ route('logout') }}">@csrf<button class="secondary" type="submit">Sign out</button></form>
    </header>
    <main id="main" class="page" tabindex="-1">
        <nav class="device-nav" aria-label="Consumer navigation"><a href="{{ route('consumer.home') }}">Overview</a><a href="{{ route('items.index') }}">My devices</a><a href="{{ route('items.create') }}">Register device or part</a><a href="{{ route('privacy.notice') }}">Privacy notice</a></nav>
        <div class="page-heading"><p class="eyebrow">Device registration and identity</p><h1 id="registration-heading" tabindex="-1">@yield('title')</h1><p>@yield('intro')</p></div>
        <x-feedback-summary :field-ids="$fieldIds ?? []" fallback-id="registration-heading" />
        @yield('content')
    </main>
    <footer class="site-footer"><span>Nairobi County case study · Use synthetic information for evaluation</span><a href="{{ route('privacy.notice') }}">Privacy notice</a></footer>
</body>
</html>
