@props(['id', 'routePrefix'])

<div class="d-inline-flex align-items-center gap-1">
    <a href="{{ route($routePrefix . '.show', $id) }}" class="btn btn-sm action-btn action-view">
        <i class="fa fa-eye"></i>
    </a>

    <a href="{{ route($routePrefix . '.edit', $id) }}" class="btn btn-sm action-btn action-edit">
        <i class="fa fa-edit"></i>
    </a>

    <form action="{{ route($routePrefix . '.delete', $id) }}" method="POST" class="d-inline">
        @csrf
        @method('DELETE')
        @if ($id != 1)
            <button type="submit" class="btn btn-sm action-btn action-delete"
                onclick="return confirm('Are you sure you want to delete this record?')">
                <i class="fa fa-trash"></i>
            </button>
        @endif
    </form>

    <div class="dropdown d-inline">
        <button class="btn btn-sm btn-light border" type="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="fa fa-ellipsis-v"></i>
        </button>
        <ul class="dropdown-menu">
            <li>
                <a class="dropdown-item" href="{{ route('admin.language.panel', $id) }}">
                    <i class="fa fa-file-code me-2"></i> {{ __('Edit Panel Json') }}
                </a>
            </li>
            <li>
                <a class="dropdown-item" href="{{ route('admin.language.mobile', $id) }}">
                    <i class="fa fa-file-code me-2"></i> {{ __('Edit Mobile Json') }}
                </a>
            </li>
        </ul>
    </div>

</div>
