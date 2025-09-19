@props(['id', 'status', 'endpoint'])

<button class="btn btn-sm toggle-status 
            {{ $status === 'active' ? 'btn-success' : 'btn-danger' }}"
    data-id="{{ $id }}" data-status="{{ $status }}" data-endpoint="{{ $endpoint }}"
    style="min-width: 100px; font-size: 13px;">
    <i class="fa {{ $status === 'active' ? 'fa-check-circle' : 'fa-times-circle' }} me-1"></i>
    {{ ucfirst($status) }}
</button>
