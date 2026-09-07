{{--
    A call-to-action button or link.

    Renders an `<a>` — every action on this page navigates, and nothing here
    submits a form or mutates state, so a `<button>` would be the wrong element
    and would lose the middle-click and "copy link" behaviour visitors expect.

    `variant`:
      primary — the brand gradient, white text. One per view, ideally.
      ghost   — outlined, sits beside a primary
      quiet   — text with an arrow, for tertiary links
      light   — for use on the navy bands, where an outline in
                `--brand-primary` would be nearly invisible

    `icon` appends a glyph after the label; `arrow` is the common case and flips
    itself in RTL through CSS rather than a second icon.

    An `href` of `#something` is an in-page jump and is left alone. Anything
    off-site gets `rel="noopener"` — the CTA target is operator-configured, so
    it can and will be an external URL.
--}}
@props([
    'href' => '#',
    'variant' => 'primary',
    'icon' => null,
    'external' => null,
])

@php
    // Inferred rather than asked for, so a call site cannot forget it.
    $isExternal = $external ?? (bool) preg_match('#^(https?:)?//#i', (string) $href);
@endphp

<a
    href="{{ $href }}"
    @if ($isExternal) target="_blank" rel="noopener noreferrer" @endif
    {{ $attributes->merge(['class' => 'btn btn--'.$variant]) }}
>
    <span class="btn-label">{{ $slot }}</span>

    @if ($icon)
        <x-landing.icon :name="$icon" :size="18" class="ic btn-icon" />
    @endif
</a>
