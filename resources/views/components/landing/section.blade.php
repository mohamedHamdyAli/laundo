{{--
    One band of the page.

    A component rather than repeated markup because thirteen sections sharing a
    heading rhythm by copy-paste is thirteen sections that drift. It owns the
    band tone, the container, and the eyebrow/title/lead block — so a section
    cannot accidentally invent its own vertical spacing or heading size.

    `tone`:
      base   — the page ground (`--surface-bg`)
      raised — the card surface (`--surface-card`), for alternation
      navy   — the brand gradient, light text

    The navy tone is used exactly **twice** on the whole page: the price-review
    band and the final call to action. Two uses make it read as emphasis; on
    every other section it would just be wallpaper.

    `id` is required when the header links to it, and becomes the
    `aria-labelledby` target so the section is announced by its own heading.
--}}
@props([
    'id' => null,
    'tone' => 'base',
    'eyebrow' => null,
    'title' => null,
    'lead' => null,
    'align' => 'start',
    'narrow' => false,
])

@php
    $headingId = $id ? $id.'-title' : null;
@endphp

<section
    @if ($id) id="{{ $id }}" @endif
    @if ($headingId && $title) aria-labelledby="{{ $headingId }}" @endif
    {{ $attributes->merge(['class' => 'band band--'.$tone]) }}
>
    <div class="container {{ $narrow ? 'container--narrow' : '' }}">
        @if ($eyebrow || $title || $lead)
            <header class="band-head band-head--{{ $align }}">
                @if ($eyebrow)
                    <p class="eyebrow">{{ $eyebrow }}</p>
                @endif

                @if ($title)
                    <h2 class="band-title" @if ($headingId) id="{{ $headingId }}" @endif>{{ $title }}</h2>
                @endif

                @if ($lead)
                    <p class="band-lead">{{ $lead }}</p>
                @endif
            </header>
        @endif

        {{ $slot }}
    </div>
</section>
