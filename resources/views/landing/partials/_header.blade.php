{{--
    The site header.

    Sticky and opaque — no `backdrop-filter`. A blurred translucent bar is the
    single most recognisable "generated landing page" tell, and over a page with
    a price table behind it, it also makes the numbers swim.

    The language switch is two plain links rather than a dropdown, because there
    are two languages: a menu that opens to reveal one alternative is a menu
    that costs a click to save nothing. It goes through `locale.set` rather than
    linking straight at `/ar` so the choice sticks in the session — otherwise
    `/` would keep resolving to the language they just switched away from.
--}}
@php
    $navLinks = [
        '#how' => webText('landing.nav.how'),
        '#services' => webText('landing.nav.services'),
        '#prices' => webText('landing.nav.prices'),
        '#coverage' => webText('landing.nav.coverage'),
        '#faq' => webText('landing.nav.faq'),
    ];

    // The current path with its language prefix removed, so switching language
    // lands on the same page rather than back at the top of the site. `/ar` and
    // `/ar/terms` become `` and `/terms`.
    $bareParts = collect(explode('/', trim((string) request()->path(), '/')))
        ->reject(fn (string $part, int $i) => $i === 0 && strlen($part) === 2 && getLanguageByCode($part) !== null)
        ->implode('/');

    $returnTo = $bareParts === '' ? '/' : '/'.$bareParts;
@endphp

<header class="site-header" data-site-header>
    <div class="container site-header-inner">

        <a class="brand" href="{{ url('/'.$language->code) }}" aria-label="{{ webText('landing.nav.home') }}">
            <img
                src="{{ brandLogo('dark') }}"
                alt="{{ webText('landing.seo.site_name') }}"
                class="brand-mark brand-mark--dark"
                width="140" height="24">
            <img
                src="{{ brandLogo('light') }}"
                alt=""
                class="brand-mark brand-mark--light"
                aria-hidden="true"
                width="140" height="24">
        </a>

        <nav class="site-nav" id="site-nav" aria-label="{{ webText('landing.nav.home') }}" data-site-nav>
            <ul class="site-nav-list">
                @foreach ($navLinks as $anchor => $label)
                    <li><a href="{{ $anchor }}">{{ $label }}</a></li>
                @endforeach
            </ul>

            <div class="site-nav-aside">
                <div class="lang-switch" role="group" aria-label="{{ webText('landing.nav.language') }}">
                    @foreach ($locales as $locale)
                        <a
                            href="{{ route('locale.set', $locale['code']).'?to='.urlencode($returnTo) }}"
                            hreflang="{{ $locale['code'] }}"
                            class="lang-switch-item @if ($locale['is_current']) is-current @endif"
                            @if ($locale['is_current']) aria-current="true" @endif
                        >{{ $locale['name'] }}</a>
                    @endforeach
                </div>

            </div>
        </nav>

        <button
            class="nav-toggle"
            type="button"
            aria-controls="site-nav"
            aria-expanded="false"
            data-nav-toggle
            data-label-open="{{ webText('landing.nav.menu_open') }}"
            data-label-close="{{ webText('landing.nav.menu_close') }}"
        >
            <span class="sr-only" data-nav-toggle-label>{{ webText('landing.nav.menu_open') }}</span>
            <x-landing.icon name="menu" :size="22" class="ic nav-toggle-open" />
            <x-landing.icon name="close" :size="22" class="ic nav-toggle-close" />
        </button>

    </div>
</header>
