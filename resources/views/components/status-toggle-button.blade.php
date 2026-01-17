@props(['id', 'status', 'endpoint', 'permission'])

@php
    $canToggle = canDo($permission);
@endphp

<button
    class="btn btn-sm
        {{ $status === 'active' ? 'btn-success' : 'btn-danger' }}
        {{ !$canToggle ? 'disabled opacity-75' : 'toggle-status' }}"

    @if($canToggle)
        data-id="{{ $id }}"
        data-status="{{ $status }}"
        data-endpoint="{{ $endpoint }}"
    @else
        disabled
    @endif

    style="min-width: 100px; font-size: 13px; cursor: {{ $canToggle ? 'pointer' : 'not-allowed' }};">

    <i class="fa {{ $status === 'active' ? 'fa-check-circle' : 'fa-times-circle' }} me-1"></i>
    {{ ucfirst($status) }}
</button>
