<form method="POST" action="{{ route('admin.roles.store') }}">
    @csrf
    <input type="text" name="name" class="form-control mb-3" placeholder="Role name">
    <button class="btn btn-role">Save</button>
</form>
