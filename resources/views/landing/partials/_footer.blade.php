{{--
    The footer.

    Every contact route and social profile here goes through `realSetting()`,
    which is why blocks are conditional rather than laid out for a full set. On
    this install `Email` is the dev team's address, `Hotline` and `Call` are
    null, and all seven social settings still point at the networks' own front
    pages — so the contact column renders only what somebody has genuinely
    filled in, and the social row does not render at all.

    A footer offering a dead link to gmail.com is worse than a footer offering
    nothing; `lessons.md` has the same lesson from the other direction, where a
    fallback asset that did not exist put a broken image in every table row.

    Social profiles are text pills rather than brand glyphs. Six hand-drawn
    brand marks is six chances to draw a wrong one, and a name reads at any
    size and in any language.
--}}
@php
    $siteName = webText('landing.seo.site_name');

    $footerNav = [
        '#how' => webText('landing.nav.how'),
        '#services' => webText('landing.nav.services'),
        '#prices' => webText('landing.nav.prices'),
        '#coverage' => webText('landing.nav.coverage'),
        '#faq' => webText('landing.nav.faq'),
    ];

    $socialNames = [
        'facebook' => 'Facebook',
        'instagram' => 'Instagram',
        'twitter' => 'X',
        'youtube' => 'YouTube',
        'linkedin' => 'LinkedIn',
        'snapchat' => 'Snapchat',
    ];
@endphp

<footer class="site-footer">
    <div class="container">

        <div class="footer-grid">

            <div class="footer-brand">
                <img
                    src="{{ brandLogo('light') }}"
                    alt="{{ $siteName }}"
                    width="150" height="26"
                    loading="lazy">
                <p class="footer-tagline">{{ webText('landing.footer.tagline') }}</p>
            </div>

            <nav class="footer-col" aria-labelledby="footer-explore">
                <h2 class="footer-heading" id="footer-explore">{{ webText('landing.footer.explore_title') }}</h2>
                <ul class="footer-list">
                    @foreach ($footerNav as $anchor => $label)
                        <li><a href="{{ url('/'.$language->code).$anchor }}">{{ $label }}</a></li>
                    @endforeach
                </ul>
            </nav>

            @if ($contact !== [])
                <div class="footer-col">
                    <h2 class="footer-heading">{{ webText('landing.footer.contact_title') }}</h2>
                    <ul class="footer-list footer-list--contact">
                        @isset($contact['phone'])
                            <li>
                                <a href="tel:{{ preg_replace('/[^\d+]/', '', $contact['phone']) }}">
                                    <x-landing.icon name="phone" :size="16" />
                                    <span>{{ $contact['phone'] }}</span>
                                </a>
                            </li>
                        @endisset
                        @isset($contact['whatsapp'])
                            <li>
                                <a href="{{ $contact['whatsapp'] }}" target="_blank" rel="noopener noreferrer">
                                    <x-landing.icon name="phone" :size="16" />
                                    <span>{{ webText('landing.footer.whatsapp_label') }}</span>
                                </a>
                            </li>
                        @endisset
                        @isset($contact['email'])
                            <li>
                                <a href="mailto:{{ $contact['email'] }}">
                                    <x-landing.icon name="mail" :size="16" />
                                    <span>{{ $contact['email'] }}</span>
                                </a>
                            </li>
                        @endisset
                    </ul>
                </div>
            @endif

            <nav class="footer-col" aria-labelledby="footer-legal">
                <h2 class="footer-heading" id="footer-legal">{{ webText('landing.footer.legal_title') }}</h2>
                <ul class="footer-list">
                    <li>
                        <a href="{{ route('landing.localised.terms', $language->code) }}">
                            {{ webText('landing.footer.legal_terms') }}
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('landing.localised.privacy', $language->code) }}">
                            {{ webText('landing.footer.legal_privacy') }}
                        </a>
                    </li>
                </ul>
            </nav>

        </div>

        @if ($social !== [])
            <div class="footer-social">
                <h2 class="footer-heading">{{ webText('landing.footer.social_title') }}</h2>
                <ul class="footer-social-list">
                    @foreach ($social as $key => $url)
                        <li>
                            <a href="{{ $url }}" target="_blank" rel="noopener noreferrer">
                                {{ $socialNames[$key] ?? ucfirst($key) }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="footer-base">
            <p>&copy; {{ now()->year }} {{ $siteName }}. {{ webText('landing.footer.rights') }}</p>

            <div class="lang-switch lang-switch--footer" role="group" aria-label="{{ webText('landing.nav.language') }}">
                @foreach ($locales as $locale)
                    <a
                        href="{{ $locale['url'] }}"
                        hreflang="{{ $locale['code'] }}"
                        class="lang-switch-item @if ($locale['is_current']) is-current @endif"
                        @if ($locale['is_current']) aria-current="true" @endif
                    >{{ $locale['name'] }}</a>
                @endforeach
            </div>
        </div>

    </div>
</footer>
