<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>@yield('title') · E-Waste Connect</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <a class="skip-link" href="#main">Skip to content</a>
    <div class="context-bar">Nairobi County · University study project</div>
    <header class="site-header">
        <a class="brand" href="{{ route('login') }}">
            <span class="brand-mark" aria-hidden="true">↻</span>
            <span>E-Waste Connect<small>Account access · Nairobi</small></span>
        </a>
        @auth
            <form method="POST" action="{{ route('logout') }}">@csrf<button class="secondary" type="submit">Sign out</button></form>
        @else
            <span class="header-label">Account access</span>
        @endauth
    </header>
    <main id="main" class="page" tabindex="-1">
        <div class="page-heading">
            <p class="eyebrow">Smart e-waste collection, tracking and recycling</p>
            <h1>@yield('title')</h1>
            <p>@yield('intro')</p>
        </div>
        @guest
            <nav class="access-nav" aria-label="Account access">
                <a href="{{ route('login') }}" @if(request()->routeIs('login')) aria-current="page" @endif>Sign in</a>
                <a href="{{ route('register') }}" @if(request()->routeIs('register')) aria-current="page" @endif>Register</a>
                <a href="{{ route('password.request') }}" @if(request()->routeIs('password.*')) aria-current="page" @endif>Recovery</a>
                <a href="{{ route('privacy.notice') }}" @if(request()->routeIs('privacy.notice')) aria-current="page" @endif>Privacy notice</a>
            </nav>
        @endguest
        <x-feedback-summary id="auth-errors" :field-ids="['email'=>'email','display_name'=>'display_name','password'=>'password','password_confirmation'=>'password_confirmation','notice_acknowledged'=>'notice_acknowledged','notice_version'=>'notice_acknowledged']" />
        <div class="workspace">
            <section class="card">@yield('content')</section>
            <aside class="card guidance" aria-label="Account guidance">
                <h2>Clear access. Private information.</h2>
                <div class="guidance-block"><h3>Your account</h3><p>Consumers can register here. Staff accounts are created by an administrator.</p></div>
                <div class="guidance-block"><h3>Recover securely</h3><p>Recovery requests give the same response for registered and unregistered emails. Reset links expire after 60 minutes and can be used once.</p></div>
                <div class="guidance-block"><h3>Understand your privacy</h3><p>Read how this study uses account information before registering. Use synthetic information for evaluation.</p><a href="{{ route('privacy.notice') }}">Read the privacy notice →</a></div>
            </aside>
        </div>
    </main>
    <footer class="site-footer"><span>E-Waste Connect · Nairobi County case study</span><a href="{{ route('privacy.notice') }}">Privacy notice</a></footer>
</body>
</html>
