@props(['id', 'status', 'endpoint', 'permission'])

@php
    $canToggle = canDo($permission);
    $isActive = $status === 'active';
@endphp

{{--
    A pill, not a filled button. The solid green/red block it used to be
    repeated once per row and outweighed the record it described; the tint
    carries the same state at a fraction of the weight and matches the status
    pill the rest of the panel now uses.

    The two labels ride along as data attributes because the click handler used
    to write «Active» / «Inactive» into the button as hard-coded English — so a
    toggle on the Arabic panel silently switched that cell to English.
--}}
<button type="button"
    class="status-pill status-toggle {{ $isActive ? 'tone-ok' : 'tone-bad' }} {{ $canToggle ? 'toggle-status' : 'is-locked' }}"
    data-label-active="{{ __('Active') }}"
    data-label-inactive="{{ __('Inactive') }}"

    @if ($canToggle)
        data-id="{{ $id }}"
        data-status="{{ $status }}"
        data-endpoint="{{ $endpoint }}"
    @else
        disabled
        aria-disabled="true"
    @endif>
    {{ $isActive ? __('Active') : __('Inactive') }}
</button>
