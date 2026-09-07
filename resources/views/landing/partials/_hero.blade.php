{{--
    The hero.

    Two columns: the claim on one side, the thing that proves it on the other.
    The proof is the price-review card, because the claim *is* the review — a
    hero illustration of clean shirts would say nothing this product does not
    share with every laundry in Cairo.

    The fact strip underneath carries counted figures only. `:count` values come
    from `LandingContentService::facts()`, which counts rows; nothing on this
    page asserts a number that is not in the database.
--}}
@php
    $ctaLabel = webText('landing.hero.cta_primary');
@endphp

<section class="hero">
    <div class="hero-glow" aria-hidden="true"></div>

    <div class="container hero-inner">

        <div class="hero-copy" data-reveal>
            <p class="hero-pill">
                <span class="hero-pill-dot" aria-hidden="true"></span>
                {{ webText('landing.hero.pill') }}
            </p>

            {{-- The page's only h1. --}}
            <h1 class="hero-title">{{ webText('landing.hero.title') }}</h1>

            <p class="hero-lead">{{ webText('landing.hero.lead') }}</p>

            <div class="hero-actions">
                <x-landing.cta :href="$cta['href']" variant="primary" icon="arrow">
                    {{ $ctaLabel }}
                </x-landing.cta>

                <x-landing.cta href="#prices" variant="ghost">
                    {{ webText('landing.hero.cta_secondary') }}
                </x-landing.cta>
            </div>

            {{--
                Only when store listings genuinely exist. "App Store" and
                "Google Play" are proper nouns and identical in both languages,
                so they are not Web File keys.
            --}}
            @if (count($cta['stores']) > 1)
                <ul class="hero-stores">
                    @isset($cta['stores']['ios'])
                        <li><a href="{{ $cta['stores']['ios'] }}" target="_blank" rel="noopener noreferrer">App Store</a></li>
                    @endisset
                    @isset($cta['stores']['android'])
                        <li><a href="{{ $cta['stores']['android'] }}" target="_blank" rel="noopener noreferrer">Google Play</a></li>
                    @endisset
                </ul>
            @endif
        </div>

        <div class="hero-visual" data-reveal>
            @include('landing.partials._review_card')
        </div>

    </div>

    @if ($facts['services'] > 0 || $facts['areas'] > 0)
        <div class="container">
            <ul class="fact-strip" data-reveal>
                @if ($facts['services'] > 0)
                    <li>{{ webText('landing.facts.services', ['count' => $facts['services']]) }}</li>
                @endif
                @if ($facts['areas'] > 0)
                    <li>{{ webText('landing.facts.areas', ['count' => $facts['areas']]) }}</li>
                @endif
                @if ($slots !== [])
                    <li>{{ webText('landing.facts.windows') }}</li>
                @endif
                <li>{{ webText('landing.facts.cash') }}</li>
            </ul>
        </div>
    @endif
</section>
