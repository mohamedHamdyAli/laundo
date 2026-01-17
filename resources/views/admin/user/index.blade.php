@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Users') }}</h5>
        @if (canDo('user.create'))
        <a href="{{ route('admin.user.create') }}" class="badge alert-info primary-background-color">
            <i class="fa fa-plus"></i> {{ __('Add User') }}
        </a>
        @endif
    </div>

    <section class="section">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-end mb-3">
                            <div class="input-group" style="max-width: 350px;">
                                <input type="text" id="userSearchInput" name="userSearch"
                                    value="{{ request('userSearch') }}" class="form-control" placeholder="Search User..">
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-borderless table-striped" id="table_list">
                                <thead class="table-light">
                                    <tr>
                                        <th>{{ __('User Image') }}</th>
                                        <th>{{ __('ID') }}</th>
                                        <th>{{ __('Name') }}</th>
                                        <th>{{ __('Phone') }}</th>
                                        <th>{{ __('Status') }}</th>
                                        <th>{{ __('Created At') }}</th>
                                        <th class="text-center">{{ __('Action') }}</th>
                                    </tr>
                                </thead>
                                <tbody id="user-table-body">
                                    @include('admin.user.partials._user_table_body', ['users' => $users])
                                </tbody>
                            </table>
                        </div>

                        <div id="pagination-wrapper">
                            {{ $users->withQueryString()->links() }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection

@push('scripts')
    <script>
        $(document).ready(function () {
            setupAjaxSearch({
                inputSelector: '#userSearchInput',
                tableBodySelector: '#user-table-body',
                paginationWrapperSelector: '#pagination-wrapper',
                url: "{{ route('admin.user.search') }}",
                colspan: 8
            });
        });
    </script>
@endpush
