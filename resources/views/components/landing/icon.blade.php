{{--
    Inline SVG icons for the landing page.

    Inline rather than an icon font because the alternative here is
    `bootstrap-icons.woff2` — 110 KB, embedded in app.css, which this page does
    not load — to draw about twenty glyphs. The whole set below is under 3 KB of
    markup and costs no request at all.

    Stroke geometry on a 24-unit grid, `currentColor`, so an icon takes the
    colour of the text it sits with and needs no per-tone variant.

    `aria-hidden` on every one: each is decorative and sits beside a real label.
    An icon that ever has to carry meaning on its own needs a `<title>`, and
    should be given one at the call site rather than here.
--}}
@props(['name', 'size' => 20])

@php
    $paths = [
        // A tick. The approve action, and the "done" marker on the timeline.
        'check' => '<path d="M4.5 12.75l4.5 4.5L19.5 6.75"/>',

        // A circular arrow: ask for the count to be done again.
        'recount' => '<path d="M19.9 11a8 8 0 1 0-2.6 6.4"/><path d="M20.5 5.5V11h-5.2"/>',

        // Somebody has a question about a line.
        'question' => '<circle cx="12" cy="12" r="9"/><path d="M9.6 9.4a2.5 2.5 0 1 1 3.6 2.3c-.8.5-1.2 1-1.2 1.9"/><path d="M12 17.1h.01"/>',

        // A time window.
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7.2V12l3.4 2"/>',

        // Leave it at the door.
        'door' => '<path d="M6 20.5V4.5a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v16"/><path d="M4.5 20.5h15"/><path d="M14.8 12h.01"/>',

        // Where the driver is.
        'pin' => '<path d="M12 21.2s6.8-6.1 6.8-10.7a6.8 6.8 0 1 0-13.6 0C5.2 15.1 12 21.2 12 21.2z"/><circle cx="12" cy="10.3" r="2.4"/>',

        // The wallet ledger.
        'wallet' => '<path d="M3.8 8.6a2 2 0 0 1 2-2h12.4a2 2 0 0 1 2 2v8.8a2 2 0 0 1-2 2H5.8a2 2 0 0 1-2-2z"/><path d="M3.8 10.6h10.4a1.6 1.6 0 0 1 0 4.8H3.8"/>',

        // A repeat schedule.
        'repeat' => '<path d="M4.5 9.2V8a2 2 0 0 1 2-2h11.3"/><path d="M15.4 3.2l2.9 2.8-2.9 2.8"/><path d="M19.5 14.8V16a2 2 0 0 1-2 2H6.2"/><path d="M8.6 20.8l-2.9-2.8 2.9-2.8"/>',

        // Invite a friend.
        'gift' => '<path d="M4.2 11.4h15.6v7.4a1.6 1.6 0 0 1-1.6 1.6H5.8a1.6 1.6 0 0 1-1.6-1.6z"/><path d="M3.4 7.8h17.2v3.6H3.4z"/><path d="M12 7.8v12.6"/><path d="M12 7.8S10.9 3.6 8.6 3.6a2.1 2.1 0 0 0 0 4.2z"/><path d="M12 7.8s1.1-4.2 3.4-4.2a2.1 2.1 0 0 1 0 4.2z"/>',

        // A driver leg.
        'truck' => '<path d="M2.8 6.6h9.6v9.2H2.8z"/><path d="M12.4 9.8h3.9l2.9 3v3h-6.8z"/><circle cx="6.6" cy="18" r="1.9"/><circle cx="16.4" cy="18" r="1.9"/>',

        // A partner laundry.
        'building' => '<path d="M4.6 20.4V5.2a1.6 1.6 0 0 1 1.6-1.6h7.4a1.6 1.6 0 0 1 1.6 1.6v15.2"/><path d="M15.2 10.4h2.6a1.6 1.6 0 0 1 1.6 1.6v8.4"/><path d="M3.2 20.4h17.6"/><path d="M8 7.6h3.6M8 11.4h3.6M8 15.2h3.6"/>',

        // The itemised count.
        'receipt' => '<path d="M5.8 3.4h12.4v17.2l-2.1-1.4-2.1 1.4-2.1-1.4-2.1 1.4-2-1.4-2 1.4z"/><path d="M9 8h6M9 11.6h6M9 15.2h3.4"/>',

        // The published price.
        'tag' => '<path d="M20.3 12.8l-7.5 7.5a1.7 1.7 0 0 1-2.4 0l-6.7-6.7V4.2a.8.8 0 0 1 .8-.8h9.4l6.4 6.4a1.7 1.7 0 0 1 0 3z"/><path d="M8.3 8.3h.01"/>',

        // Then we wash.
        'droplet' => '<path d="M12 3.2s6.3 6.1 6.3 10.3a6.3 6.3 0 1 1-12.6 0C5.7 9.3 12 3.2 12 3.2z"/>',

        // Your count beside theirs.
        'scales' => '<path d="M12 4.2v16.2"/><path d="M7.4 20.4h9.2"/><path d="M4 8.2l16-1.6"/><path d="M4 8.2L1.9 13a2.6 2.6 0 0 0 4.2 0z"/><path d="M20 6.6L17.9 11.4a2.6 2.6 0 0 0 4.2 0z"/>',

        // The handover scan.
        'qr' => '<path d="M3.8 3.8h5.4v5.4H3.8z"/><path d="M14.8 3.8h5.4v5.4h-5.4z"/><path d="M3.8 14.8h5.4v5.4H3.8z"/><path d="M14.8 14.8h2.2M20.2 14.8v2.4M14.8 18.4v1.8M18.4 20.2h1.8"/>',

        'arrow' => '<path d="M4.8 12h14.4"/><path d="M13.6 6l5.6 6-5.6 6"/>',
        'chevron' => '<path d="M6.5 9.5l5.5 5.5 5.5-5.5"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'close' => '<path d="M6 6l12 12M18 6L6 18"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3.3 9.6h17.4M3.3 14.4h17.4"/><path d="M12 3a14 14 0 0 1 0 18 14 14 0 0 1 0-18z"/>',
        'mail' => '<path d="M3.4 6.6h17.2v10.8H3.4z"/><path d="M3.4 7.2l8.6 6 8.6-6"/>',
        'phone' => '<path d="M8.2 3.6H5.6a1.9 1.9 0 0 0-1.9 2.1C4.4 13 11 19.6 18.3 20.3a1.9 1.9 0 0 0 2.1-1.9v-2.6l-4.3-1.4-1.9 2.2a15 15 0 0 1-5.2-5.2l2.2-1.9z"/>',

        // Show the password. Its struck-through twin is drawn by adding the
        // slash in the markup rather than as a second entry, so the two states
        // cannot drift apart in size or weight.
        'eye' => '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/>',
    ];
@endphp

@if (isset($paths[$name]))
    <svg {{ $attributes->merge(['class' => 'ic']) }}
         width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24"
         fill="none" stroke="currentColor" stroke-width="1.7"
         stroke-linecap="round" stroke-linejoin="round"
         aria-hidden="true" focusable="false">{!! $paths[$name] !!}</svg>
@endif
