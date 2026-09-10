@php
    $currentLangCode = panelLanguageCode();
    $appLogoLight = brandLogo('light');
    $appName = getSettingValue('App_Name') ?: config('app.name');
@endphp
<!DOCTYPE html>
{{-- `class="landing"` is load-bearing, not cosmetic: landing.css puts its dark
     token blocks on `html.landing` and resets the root font size to 100% there,
     undoing the panel's 87.5% density zoom. Without it this page reads the
     light tokens in a dark browser and renders at the panel's scale. --}}
<html class="landing" lang="{{ $currentLangCode }}"
    dir="{{ $currentLangCode === 'ar' ? 'rtl' : 'ltr' }}">

<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- The same blocking theme resolution the landing layout does, and for the
         same reason: somebody who chose dark is not flashed a white page on the
         way from the marketing site to this form. Wrapped because localStorage
         throws outright in some privacy modes. --}}
    <script>
        (function () {
            try {
                var t = localStorage.getItem('theme');
                if (t === 'theme-dark' || t === 'theme-light') {
                    document.documentElement.classList.add(t);
                }
            } catch (e) {}
        })();
    </script>

    <link rel="icon" type="image/png" href="{{ asset('assets/images/brand/laundo-mark.png') }}">
    <title>@yield('title', __('Laundry Sign In'))</title>

    {{-- Not `layouts.auth`'s stylesheet set.

         That shell is the panel's: Nunito, the vendor `app.css`, and no Arabic
         face at all — «مغسلتك، بين إيديك» renders in whatever the browser
         happens to pick. These pages are the first thing a laundry ever sees of
         Laundo and they are arrived at from the landing page, so they take the
         landing page's typography instead: IBM Plex Sans Arabic, self-hosted,
         already shipped. Nothing else is pulled in — no jQuery, no bootstrap,
         no select2. --}}
    <link rel="stylesheet" href="{{ asset('assets/css/landing.css') }}?v={{ landingAssetVersion('css/landing.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/auth-card.css') }}?v={{ landingAssetVersion('css/auth-card.css') }}">

    {{-- Components push here. `x-map-picker` ships its own styles and expects a
         stack to put them in; without one the register form's map renders
         unstyled. --}}
    @stack('styles')
</head>

<body class="auth-page">
    <div class="auth-ground">
        <a class="auth-brand" href="{{ url('/'.$currentLangCode) }}">
            <img src="{{ $appLogoLight }}" alt="{{ $appName }}">
        </a>

        <main class="auth-card @yield('card-modifier')" role="main">
            <h1 class="auth-title">@yield('heading')</h1>
            <p class="auth-subtitle">@yield('subtitle')</p>

            @if (session('status'))
                <p class="auth-note is-good">{{ session('status') }}</p>
            @endif

            @yield('form')
        </main>

        @hasSection('footnote')
            <p class="auth-footnote">@yield('footnote')</p>
        @endif
    </div>

    @stack('scripts')
</body>

</html>
