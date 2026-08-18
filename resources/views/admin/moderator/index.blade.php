@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Moderators') }}</h5>
        @if (canDo('moderator.create'))
            <a href="{{ route('admin.moderator.create') }}" class="badge alert-info primary-background-color">
                <i class="fa fa-plus"></i> {{ __('Add Moderator') }}
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
                                <input type="text" id="moderatorSearchInput" name="moderatorSearch"
                                    value="{{ request('moderatorSearch') }}" class="form-control"
                                    placeholder="Search Moderator...">
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-borderless table-striped" id="table_list">
                                <thead class="table-light">
                                    <tr>
                                        <th>{{ __('Image') }}</th>
                                        <th>{{ __('ID') }}</th>
                                        <th>{{ __('Name') }}</th>
                                        <th>{{ __('Phone') }}</th>
                                        <th>{{ __('Role') }}</th>
                                        <th>{{ __('Status') }}</th>
                                        <th>{{ __('Created At') }}</th>
                                        <th class="text-center">{{ __('Action') }}</th>
                                    </tr>
                                </thead>
                                <tbody id="moderator-table-body">
                                    @include('admin.moderator.partials._moderator_table_body', ['moderators' => $moderators])
                                </tbody>
                            </table>
                        </div>

                        <div id="pagination-wrapper">
                            {{ $moderators->withQueryString()->links() }}
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
                inputSelector: '#moderatorSearchInput',
                tableBodySelector: '#moderator-table-body',
                paginationWrapperSelector: '#pagination-wrapper',
                url: "{{ route('admin.moderator.search') }}",
                colspan: 8
            });
        });
    </script>
@endpush
