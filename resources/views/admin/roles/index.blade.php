@extends('layouts.main')
@section('content')
    {{-- The page header follows the rest of the panel now: a plain title on the
         page ground, with the primary action beside it. It used to be a
         full-width saturated blue banner with white type — the only screen in
         the dashboard that announced itself that way.

         The title also said «Permission» while the page lists roles, which is
         what the sidebar calls it and what the reader came looking for. --}}
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="card-title mb-0">{{ __('Roles') }}</h5>

        @if (canDo('role.create'))
            {{-- Same job on the page as every other list's «Add …», so the same
                 treatment. The bare «+ » was a plus sign typed into the label
                 rather than an icon. --}}
            <button type="button" class="btn-add" data-bs-toggle="modal" data-bs-target="#createRoleModal">
                <i class="fa fa-plus"></i>{{ __('Create Role') }}
            </button>
        @endif
    </div>

    <section class="section">

        @foreach ($roles as $role)
            <div class="card mb-3 shadow-sm">

                {{-- Role Row --}}
                <div class="card-body d-flex justify-content-between align-items-center">
                    <strong>{{ $role->name }}</strong>

                    {{-- Opening the permission grid is a secondary action; as a
                         solid fill it was as loud as the page's primary. --}}
                    <button type="button" class="btn-quiet toggle-permissions" data-role="{{ $role->id }}">
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
                        <button type="submit" class="btn btn-primary">
                            {{ __('Save Role') }}
                        </button>
                    </div>
                </form>

            </div>
        </div>
    </section>
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
