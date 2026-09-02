@php
    $currentLangCode = Session::get('language')?->code ?? 'en';
@endphp
<!DOCTYPE html>
<html lang="{{ $currentLangCode }}" dir="{{ $currentLangCode === 'ar' ? 'rtl' : 'ltr' }}">
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
</head>
<body>
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
