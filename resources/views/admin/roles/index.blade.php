@extends('layouts.main')
@section('content')
    <div class="container-fluid">

        {{-- Header --}}
        <div class="d-flex justify-content-between align-items-center mb-4 p-3 role-header">
            <h4 class="text-white mb-0">{{ __('Permission') }}</h4>

            @if (canDo('role.create'))
                <button class="btn btn-role" data-bs-toggle="modal" data-bs-target="#createRoleModal">
                    + {{ __('Create Role') }}
                </button>
            @endif
        </div>

        @foreach ($roles as $role)
            <div class="card mb-3 shadow-sm">

                {{-- Role Row --}}
                <div class="card-body d-flex justify-content-between align-items-center">
                    <strong>{{ $role->name }}</strong>

                    <button type="button" class="btn btn-role toggle-permissions" data-role="{{ $role->id }}">
                        {{ __('Permission') }}
                    </button>
                </div>

                {{-- Permissions Section (Hidden) --}}
                <div class="permissions-box d-none" id="permissions-{{ $role->id }}">
                    <form method="POST" action="{{ route('admin.roles.permissions.update', $role) }}">
                        @csrf

                        <div class="permission-header row text-white">
                            <div class="col-3">{{ __('Module') }}</div>
                            <div class="col text-center">{{ __('View') }}</div>
                            <div class="col text-center">{{ __('Add') }}</div>
                            <div class="col text-center">{{ __('Edit') }}</div>
                            <div class="col text-center">{{ __('Delete') }}</div>
                            <div class="col text-center">{{ __('Toggle') }}</div>
                            <div class="col text-end">
                                <button class="btn btn-primary btn-sm">{{ __('Save') }}</button>
                            </div>
                        </div>

                        @foreach ($permissions as $model => $modelPermissions)
                            <div class="permission-row row align-items-center">
                                <div class="col-3 fw-bold">
                                    {{ ucfirst($model) }}
                                </div>

                                @foreach (['view', 'create', 'update', 'delete', 'toggle'] as $action)
                                    @php
                                        $slug = "$model.$action";
                                    @endphp
                                    <div class="col text-center">
                                        <input type="checkbox" name="permissions[]" value="{{ $slug }}"
                                            @checked($role->permissions->contains('slug', $slug))>
                                    </div>
                                @endforeach

                                <div class="col"></div>
                            </div>
                        @endforeach
                    </form>
                </div>

            </div>
        @endforeach
    </div>
    <div class="modal fade" id="createRoleModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">

                <form method="POST" action="{{ route('admin.roles.store') }}">
                    @csrf

                    <div class="modal-header">
                        <h5 class="modal-title">{{ __('Create New Role') }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <label class="mb-2">{{ __('Role Name') }}</label>
                        <input type="text" name="name" class="form-control" placeholder="{{ __('ex: Manager') }}" required>
                    </div>

                    <div class="modal-footer">
                        <button type="submit" class="btn btn-role">
                            {{ __('Save Role') }}
                        </button>
                    </div>
                </form>

            </div>
        </div>
    </div>
@endsection

<script>
    document.addEventListener('DOMContentLoaded', function() {

        document.querySelectorAll('.toggle-permissions').forEach(function(btn) {
            btn.addEventListener('click', function() {

                const roleId = this.getAttribute('data-role');
                const box = document.getElementById('permissions-' + roleId);

                if (!box) {
                    console.error('Permissions box not found for role:', roleId);
                    return;
                }

                box.classList.toggle('d-none');
            });
        });

    });
</script>
