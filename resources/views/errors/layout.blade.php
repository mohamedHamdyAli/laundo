@php
    /*
     |--------------------------------------------------------------------------
     | The error shell — and the one rule that shapes all of it
     |--------------------------------------------------------------------------
     |
     | **Nothing on this page may touch anything that can fail.** No database, no
     | session, no cache, no settings, no authenticated user.
     |
     | That is not caution for its own sake. `CACHE_STORE` and `SESSION_DRIVER`
     | are both `database` on this install, so a database that is down takes the
     | cache and the session with it — and a database that is down is the
     | commonest cause of the 500 this page exists to render. A shell that read
     | `getSettingValue('App_Name')` would throw *while rendering the error
     | page*, and Laravel would fall back to its bare built-in page: the nice
     | design would fail in exactly the case it was built for.
     |
     | So, specifically, and please keep it this way:
     |
     |   * not `layouts.main`     — 22 stylesheets, 30 scripts, and a view
     |                              composer on `layouts.topbar` that reads the
     |                              languages table on every render
     |   * not `layouts.auth-card` — right look, wrong dependencies: it opens
     |                              with panelLanguageCode(), brandLogo() and
     |                              getSettingValue(), which are three reads
     |   * no `@csrf`             — that is the session
     |   * no `panelIsRtl()`      — reads the languages table; the locale is
     |                              enough to decide direction
     |
     | `app()->getLocale()` is config and request state, never a query.
     | `landingAssetVersion()` is `filemtime()`. `asset()` is string work. Those
     | three are the whole of what this page is allowed to call.
     */
    /*
     | The locale is decided by the `errors.*` composer in ViewServiceProvider,
     | not here — it has to be settled *before* the child view runs, because
     | `errors/404.blade.php` translates its own title in a `@section` and Blade
     | evaluates those before it renders this parent. See the note there.
     */
    $locale = app()->getLocale();
    $rtl = in_array($locale, ['ar', 'fa', 'he', 'ur'], true);
@endphp
<!DOCTYPE html>
{{-- `class="landing"` is load-bearing, not cosmetic: landing.css scopes its dark
     tokens to `html.landing` and resets the root font size to 100% there,
     undoing the panel's 87.5% density zoom. Without it this page reads the light
     tokens and renders at the panel's scale. --}}
<html class="landing" lang="{{ $locale }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">

    <title>@yield('code') · @yield('title')</title>

    <link rel="icon" type="image/png" href="{{ asset('assets/images/brand/laundo-mark.png') }}">

    {{-- landing.css first: it carries the design tokens and the two self-hosted
         IBM Plex Sans Arabic faces. error.css adds only this page's layout, so
         there is no third copy of the token block for theme.css to drift from.

         Both are fingerprinted with filemtime() — neither has a build step, so
         nothing else would bust a visitor's cache after a deploy.

         **The path passed to `landingAssetVersion()` is relative to `assets/`,
         which `asset()`'s is not.** The helper prepends `assets/` itself, so
         `assets/css/error.css` sends it looking for `assets/assets/css/...`,
         which does not exist — and it then falls back to `app()->version()`, a
         constant. The tag still renders a plausible `?v=`, the file is simply
         never busted again. That shipped: a fixed stylesheet sat behind a stale
         cached copy on production while the HTML around it updated. --}}
    <link rel="stylesheet" href="{{ asset('assets/css/landing.css') }}?v={{ landingAssetVersion('css/landing.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/error.css') }}?v={{ landingAssetVersion('css/error.css') }}">
</head>

<body>
    <main class="error-page">
        <div class="error-card">
            {{-- The full wordmark, not `laundo-mark.png` — that one is the square
                 «L» badge and it is the favicon's job, not the page's. The
                 `-light` file is the same mark drawn white, which is what a navy
                 ground needs; it is a static file, so unlike `brandLogo()` it
                 costs no settings read. `alt` is empty on purpose: the name is
                 already in the title and in the tab, and a screen reader
                 announcing «Laundo» before «404» buries the thing that matters. --}}
            <img src="{{ asset('assets/images/brand/laundo-light.png') }}" alt="" class="error-brand">

            <p class="error-code">@yield('code')</p>

            <h1 class="error-title">@yield('title')</h1>

            <p class="error-message">@yield('message')</p>

            <div class="error-actions">
                {{-- history.back() rather than a referrer: the referrer is often
                     empty (a typed URL, a bookmark, a link from an email) and a
                     button that goes nowhere is worse than no button. Hidden
                     outright when there is nothing to go back to. --}}
                <button type="button" class="error-btn error-btn-quiet" id="error-back" hidden>
                    {{ __('Go back') }}
                </button>

                <a href="{{ url('/') }}" class="error-btn error-btn-primary">
                    {{ __('Back to home') }}
                </a>
            </div>

            @hasSection('meta')
                <div class="error-meta">@yield('meta')</div>
            @endif
        </div>
    </main>

    <script>
        // Only offered when the tab actually has somewhere to go back to.
        (function () {
            if (window.history.length > 1) {
                var b = document.getElementById('error-back');
                b.hidden = false;
                b.addEventListener('click', function () { window.history.back(); });
            }
        })();
    </script>
</body>

</html>
