<div>
    @props(['id', 'routePrefix'])

    <div class="d-inline-flex align-items-center">
        <a href="{{ route($routePrefix . '.show', $id) }}" class="btn btn-sm action-btn action-view me-1">
            <i class="fa fa-eye"></i>
        </a>

        <a href="{{ route($routePrefix . '.edit', $id) }}" class="btn btn-sm action-btn action-edit me-1">
            <i class="fa fa-edit"></i>
        </a>

        <form action="{{ route($routePrefix . '.delete', $id) }}" method="POST" class="d-inline">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-sm action-btn action-delete"
                onclick="return confirm('Are you sure you want to delete this record?')">
                <i class="fa fa-trash"></i>
            </button>
        </form>
    </div>

</div>
