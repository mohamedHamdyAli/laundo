{{--
    The public site's layout.

    Deliberately **not** `layouts.main`. That chain pulls ~22 stylesheets and
    ~30 scripts — app.css alone is 399 KB, theme.css 93 KB, bootstrap-icons
    another 110 KB of webfont, plus ApexCharts, TinyMCE, select2, FilePond,
    jsTree and jQuery UI. All of it is right for a dense admin panel and all of
    it is wrong for the one page whose largest-contentful-paint anybody measures.

    So this loads exactly one stylesheet and one deferred script, and the icons
    are inline SVG. `landing.css` re-declares the design tokens rather than
    importing theme.css; `LandingTokenParityTest` fails the build if a value
    drifts from it, which is what keeps the duplication honest.
--}}
@php
    $langCode = $language->code;
    $direction = $isRtl ? 'rtl' : 'ltr';
@endphp
    <!DOCTYPE html>
<html class="landing" lang="{{ $langCode }}" dir="{{ $direction }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{--
        Theme resolution, inline and blocking, before any paint.

        Three states, matching the panel: an explicit choice wins, and with no
        choice stored the OS preference decides through a media query in the
        stylesheet. The panel writes `theme-dark` / `theme-light` to the same
        localStorage key (`app.js`), so somebody who set dark there is not
        flashed a white page here.

        The class lands on `<html>` rather than `<body>` because the token
        blocks are on `:root` and this has to be resolved before the first
        paint; the panel's own toggle predates that and uses `<body>`.

        Wrapped because localStorage throws outright in some privacy modes.
    --}}
    <script>
        (function () {
            var root = document.documentElement;

            try {
                var t = localStorage.getItem('theme');
                if (t === 'theme-dark' || t === 'theme-light') {
                    root.classList.add(t);
                }
            } catch (e) {}

            // Arms the reveal animation, and does it here rather than in
            // landing.js on purpose. The hiding rules are scoped to
            // `.js-reveal`, so a deferred script adding this class would show
            // the page, hide it, then fade it back in. Decided before first
            // paint instead — and only when there is something to animate
            // with and somebody who wants it.
            try {
                if ('IntersectionObserver' in window &&
                    !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                    root.classList.add('js-reveal');
                }
            } catch (e) {}
        })();
    </script>

    @include('landing.partials._seo')

    <link rel="icon" type="image/png" href="{{ asset('assets/images/brand/laundo-mark.png') }}">

    {{--
        Only the two faces this language will actually paint with.

        Arabic text renders in IBM Plex Sans Arabic and everything else in
        Nunito — the split is done by `unicode-range`, so preloading the wrong
        family would fetch a file the page never draws a glyph from. Nunito was
        already on disk and had no `@font-face` outside app.css, which this
        layout does not load; landing.css declares it.
    --}}
    @if ($isRtl)
        <link rel="preload" as="font" type="font/woff2" crossorigin
              href="{{ asset('assets/fonts/vendor/ibm-plex-sans-arabic/ibm-plex-sans-arabic-arabic-400-normal.woff2') }}">
        <link rel="preload" as="font" type="font/woff2" crossorigin
              href="{{ asset('assets/fonts/vendor/ibm-plex-sans-arabic/ibm-plex-sans-arabic-arabic-700-normal.woff2') }}">
    @else
        <link rel="preload" as="font" type="font/woff2" crossorigin
              href="{{ asset('assets/fonts/vendor/@fontsource/nunito/files/nunito-latin-400-normal.woff2') }}">
        <link rel="preload" as="font" type="font/woff2" crossorigin
              href="{{ asset('assets/fonts/vendor/@fontsource/nunito/files/nunito-latin-700-normal.woff2') }}">
    @endif

    {{--
        Fingerprinted by modification time.

        These two files are served straight out of `public/` with no build step
        behind them, so a deploy that changes them cannot rely on a hashed
        filename to invalidate anything — a returning visitor would keep the
        stylesheet they cached last week. `filemtime()` is enough: it changes
        exactly when the file does, and costs one stat call.
    --}}
    <link rel="stylesheet" href="{{ asset('assets/css/landing.css').'?v='.landingAssetVersion('css/landing.css') }}">
</head>
<body>
<a class="skip-link" href="#main">{{ webText('landing.nav.skip') }}</a>

@include('landing.partials._header')

<main id="main">
    @yield('content')
</main>

@include('landing.partials._footer')

<script src="{{ asset('assets/js/landing.js').'?v='.landingAssetVersion('js/landing.js') }}" defer></script>
</body>
</html>
