@php
    $currentLangCode = panelLanguageCode();
    $appLogoDark = brandLogo('dark');
    $appName = getSettingValue('App_Name') ?: config('app.name');
@endphp
<!DOCTYPE html>
<html lang="{{ $currentLangCode }}" dir="{{ $currentLangCode === 'ar' ? 'rtl' : 'ltr' }}">

<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- The bundled favicon was the vendor template's own logo — a cyan
         magnifying glass, another product's brand mark, in the tab of every
         page. `laundo-mark.png` is the leading letterform of the wordmark,
         cropped square so it still reads at 16px. --}}
    <link rel="icon" type="image/png" href="{{ asset('assets/images/brand/laundo-mark.png') }}">
    <title>@yield('title', __('Login'))</title>

    {{-- Neither `app.css` nor the vendor's `auth.css`.

         This screen used to load the whole panel stylesheet to draw two inputs
         and a button, and inherited a cyan `btn-primary` belonging to no
         palette in this application along with it. It now loads `landing.css`
         for the one thing the panel does not have — a self-hosted Arabic
         typeface — and its own file for everything else. --}}
    <link rel="stylesheet" href="{{ asset('assets/css/landing.css') }}?v={{ landingAssetVersion('css/landing.css') }}">
    <link rel="stylesheet"
        href="{{ asset('assets/css/auth-command.css') }}?v={{ landingAssetVersion('css/auth-command.css') }}">
</head>

<body class="hall">
    {{-- Three soft shapes drifting behind everything, over three quarters of a
         minute. Decorative and nothing else, so it is hidden from assistive
         technology and stopped entirely for reduced motion. --}}
    <div class="hall-bg" aria-hidden="true">
        <span></span><span></span><span></span>
    </div>

    {{-- What the panel governs, either side of the card.

         Illustrative, and it says so: each figure carries «مثال». Real counts
         are the obvious temptation and the wrong answer — this page is
         reachable by anybody, and publishing how many orders a business took
         today to whoever loads its sign-in screen is a business fact given
         away for a decoration. Invented figures with no marking would be the
         other wrong answer: a reader has no way to know, and every number on
         a screen is read as a claim. --}}
    <div class="hall-orbit" aria-hidden="true">
        @foreach ([
            ['side' => 'start', 'icon' => 'receipt', 'value' => '128', 'label' => __('Orders today')],
            ['side' => 'start', 'icon' => 'truck', 'value' => '24', 'label' => __('Drivers on shift')],
            ['side' => 'end', 'icon' => 'building', 'value' => '36', 'label' => __('Partner laundries')],
            ['side' => 'end', 'icon' => 'clock', 'value' => '19', 'label' => __('Journeys running')],
        ] as $i => $chip)
            <div class="hall-chip is-{{ $chip['side'] }}" style="--chip: {{ $i }}">
                <span class="hall-chip-icon"><x-landing.icon :name="$chip['icon']" :size="18" /></span>
                <span class="hall-chip-body">
                    <span class="hall-chip-value">{{ $chip['value'] }}</span>
                    <span class="hall-chip-label">{{ $chip['label'] }}</span>
                </span>
                <span class="hall-chip-note">{{ __('Example') }}</span>
            </div>
        @endforeach
    </div>

    <main class="hall-stage">
        <img class="hall-mark" src="{{ $appLogoDark }}" alt="{{ $appName }}">
        <hr class="hall-rule">

        <div class="hall-card">
            <h1 class="hall-heading">@yield('heading')</h1>
            <p class="hall-sub">@yield('subtitle')</p>

            @if (session('status'))
                <p class="hall-note is-good">{{ session('status') }}</p>
            @endif

            @yield('form')
        </div>

        {{-- Outside the card: a way off this page, not part of signing in to
             it. A laundry owner who lands on the operator's door needs it. --}}
        <p class="hall-door">
            {{ __('Running a laundry with us?') }}
            <a href="{{ route('laundry.login') }}">{{ __('Sign in to your laundry') }}</a>
        </p>
    </main>

    @stack('scripts')
</body>

</html>
