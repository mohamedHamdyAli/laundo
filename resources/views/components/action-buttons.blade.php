<div>
    @props(['id', 'routePrefix', 'roleKey'])

    <div class="d-inline-flex align-items-center">
        @if (canDo("$roleKey.view"))
            <a href="{{ route("$routePrefix.show", $id) }}" class="btn btn-sm action-btn action-view me-1"
                title="{{ __('View') }}" aria-label="{{ __('View') }}">
                <i class="fa fa-eye"></i>
            </a>
        @endif
        @if (canDo("$roleKey.update"))
            <a href="{{ route("$routePrefix.edit", $id) }}" class="btn btn-sm action-btn action-edit me-1"
                title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}">
                <i class="fa fa-edit"></i>
            </a>
        @endif
        @if (canDo("$roleKey.delete"))
            <form action="{{ route("$routePrefix.delete", $id) }}" method="POST" class="d-inline">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-sm action-btn action-delete"
                    title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}"
                    onclick="return confirm('{{ __('Are you sure you want to delete this record?') }}')">
                    <i class="fa fa-trash"></i>
                </button>
            </form>
        @endif

    </div>

</div>
