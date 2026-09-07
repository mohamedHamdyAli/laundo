{{--
    Head metadata for the public pages.

    There was none anywhere in this project before: `layouts/main` sets a bare
    `<title>{{ config('app.name') }}` and no description, no canonical, no
    Open Graph. That is fine for a panel behind a login and useless for the one
    page meant to be found.

    Every string here comes from the Web File, so the title and the description
    a search result shows are an operator's to edit at
    /admin/language/web/{id} — the same place the rest of the page's copy lives.

    Note `og:site_name` does **not** read `App_Name`. That setting still holds
    the template's `BaseCode` on this install, and it is deliberately left alone
    (an invoice may need a registered legal name — the owner's call), so the
    public brand name is its own Web File key.
--}}
@php
    $siteName = webText('landing.seo.site_name');

    // The legal pages pass their own title; the landing page uses the SEO key.
    $metaTitle = isset($title)
        ? $title.' — '.$siteName
        : webText('landing.seo.title');

    $metaDescription = webText('landing.seo.description');

    // `en_US` / `ar_EG`, straight off `languages.country_code`. Omitted rather
    // than guessed when the column is empty: a wrong og:locale is worse than
    // none, because Facebook then treats the page as en_US.
    $ogLocale = filled($language->country_code)
        ? $language->code.'_'.strtoupper($language->country_code)
        : null;

    $ogImage = asset('assets/images/brand/laundo-og.png');
@endphp

<title>{{ $metaTitle }}</title>
<meta name="description" content="{{ $metaDescription }}">

<link rel="canonical" href="{{ $canonical }}">

{{--
    One URL per language, which is the entire reason `/ar` and `/en` exist. A
    session-based switch on a single `/` cannot be expressed as hreflang, so a
    crawler would only ever index whichever language it happened to be handed.

    `x-default` is `/` — the address that resolves the language itself.
--}}
@foreach ($locales as $locale)
    <link rel="alternate" hreflang="{{ $locale['code'] }}" href="{{ $locale['url'] }}">
@endforeach
<link rel="alternate" hreflang="x-default" href="{{ url('/') }}">

<meta property="og:type" content="website">
<meta property="og:site_name" content="{{ $siteName }}">
<meta property="og:title" content="{{ $metaTitle }}">
<meta property="og:description" content="{{ $metaDescription }}">
<meta property="og:url" content="{{ $canonical }}">
@if ($ogLocale)
    <meta property="og:locale" content="{{ $ogLocale }}">
@endif
<meta property="og:image" content="{{ $ogImage }}">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="{{ webText('landing.seo.og_image_alt') }}">

<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $metaTitle }}">
<meta name="twitter:description" content="{{ $metaDescription }}">
<meta name="twitter:image" content="{{ $ogImage }}">

{{--
    Structured data, kept to the two types this page can actually support.

    `Organization` and `FAQPage` and nothing else. Explicitly **not**:

      - `LocalBusiness`, which wants a postal address — none is configured, and
        inventing one would be a fabricated business detail
      - `Service` / `Product` with `offers`, because prices move and Google
        would keep serving a cached figure the checkout no longer agrees with
      - anything carrying `aggregateRating` or `review`: `order_ratings` holds
        zero rows, so any rating here would be invented

    `sameAs` is filtered through `realSetting()` upstream, so it lists only
    profiles somebody has actually set. On this install every social setting is
    still the network's own front page, so the key is omitted entirely rather
    than pointing search engines at facebook.com.
--}}
@php
    $organisation = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'Organization',
        'name' => $siteName,
        'url' => url('/'),
        'logo' => asset('assets/images/brand/laundo-mark.png'),
        'description' => $metaDescription,
        'sameAs' => array_values($social ?? []) ?: null,
    ]);

    $faqSchema = collect($faqs ?? [])
        ->map(fn (array $faq) => [
            '@type' => 'Question',
            'name' => $faq['question'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $faq['answer']],
        ])
        ->values()
        ->all();
@endphp

<script type="application/ld+json">{!! json_encode($organisation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>

@if ($faqSchema !== [])
    <script type="application/ld+json">{!! json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => $faqSchema,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
@endif
