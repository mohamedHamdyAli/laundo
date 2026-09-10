@php
    // `Session::get('language')?->code ?? 'en'` ignored the default language
    // row, and `=== 'ar'` made Arabic the only language that could ever be
    // right-to-left. Both now come from the `languages` table.
    $currentLangCode = panelLanguageCode();
@endphp
<!DOCTYPE html>
<html lang="{{ $currentLangCode }}" dir="{{ panelIsRtl() ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    {{-- The bundled favicon was the vendor template's own logo — a cyan
         magnifying glass, another product's brand mark, sitting in the tab of
         every page. `laundo-mark.png` is the leading letterform of the Laundo
         wordmark, cropped square and padded so it still reads at 16px, where
         the wide wordmark is only a smear.

         `type="image/png"`, not `image/x-icon`: the file was always a PNG, and
         some browsers ignore a link whose declared type does not match and fall
         back to /favicon.ico — which ships empty here, hence the blank tab. --}}
    <link rel="icon" type="image/png" href="{{ asset('assets/images/brand/laundo-mark.png') }}">
    <title>{{ config('app.name') }}</title>
    <meta name="csrf-token" content="{{ csrf_token() }}"/>
    @include('layouts.include')
    @yield('css')
    {{-- Components push here. `@yield('css')` can only be filled once per view,
         so a component rendered inside a form had no way to ship its own styles
         without the page knowing about them. --}}
    @stack('styles')
</head>
<body>
{{-- The splash.

     It is markup and not a jQuery append any more: the old one was added by
     custom.js, which loads at the bottom of the page, so the "loading" screen
     arrived after the thing it was meant to cover. Here it paints on the first
     frame.

     The wordmark is revealed a letter at a time by a six-step clip wipe over
     the real logo — six steps because LAUNDO has six letters, and animating the
     brand asset itself means an uploaded logo still gets a sensible reveal
     instead of a hand-drawn imitation of one. `brand-loader.js` takes it away
     the moment both the wipe and the page are done. --}}
{{-- The inline styles are not laziness, they are the failure mode.

     This element paints before any stylesheet is guaranteed to have arrived,
     and on the deploy that introduced it `theme.css` was still being served
     from cache — so the rules that make it a full-screen overlay did not
     exist and a 240px logo rendered inline across the top of every page,
     over content nobody could click. Enough of the layout to be harmless
     lives here where nothing can strip it; `theme.css` still owns the wipe,
     the dark ground and the fade. --}}
<div id="brand-loader" aria-hidden="true"
    style="position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;background:#fff">
    <img src="{{ brandLogo('dark') }}" alt="" class="brand-loader-mark"
        style="width:min(240px,52vw);height:auto">
</div>
{{-- Here rather than with the rest of the scripts: it has to be listening
     before the wipe can finish, and everything else loads after the page. --}}
<script src="{{ asset('assets/js/custom/brand-loader.js') }}?v={{ assetVersion('js/custom/brand-loader.js') }}"></script>
<div id="app">
    @include('layouts.sidebar')
    <div id="main" class='layout-navbar'>
        @include('layouts.topbar')
        <div id="main-content">
            {{-- Only render the heading slot when a view actually fills it.
                 No view does today, and an unconditional wrapper was leaving an
                 empty 0-height div carrying 2rem+1rem of margin at the top of
                 every one of the 100+ screens. --}}
            @hasSection('page-title')
                <div class="page-heading">
                    @yield('page-title')
                </div>
            @endif
            @yield('content')
        </div>
        {{-- The footer belongs to the content column, not to #app. Outside
             #main it ignored the sidebar offset, which is why the vendored
             stylesheet had to pin it with position:fixed across the whole
             viewport — a full-width white bar over every screen, for a
             copyright line. In here it is an ordinary block that ends the
             page; theme.css unpins it. --}}
        @include('layouts.footer')
    </div>
</div>
@include('layouts.footer_script')
@yield('js')
@yield('script')
@stack('scripts')

</body>
</html>
