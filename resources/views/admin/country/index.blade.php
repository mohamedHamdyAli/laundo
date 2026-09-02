@extends('layouts.main')


@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Country') }}</h5>
        @if (canDo('country.create'))
        <a href="{{ route('admin.country.create') }}" class="btn-add">
            <i class="fa fa-plus"></i>{{ __('Add Country') }}
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
                                <input type="text" id="countrySearchInput" name="countrySearch"
                                    value="{{ request('countrySearch') }}" class="form-control"
                                    placeholder="Search Country...">
                            </div>
                        </div>
                        <div id="search-info" class="mb-3 small text-muted text-end" style="display: none;"></div>

                        @php
                            // Shared by the label strip and every row, so the labels
                            // sit exactly over the fields they name.
                            $stackCols = 'minmax(9rem,1.4fr) minmax(6rem,1fr) minmax(7rem,auto) minmax(8rem,1fr) minmax(6rem,auto)';
                        @endphp

                        <div class="stack-head" style="--stack-cols: {{ $stackCols }}">
                            <span>{{ __('Name') }}</span>
                            <span>{{ __('Code') }}</span>
                            <span>{{ __('Status') }}</span>
                            <span>{{ __('Created At') }}</span>
                            <span class="text-end">{{ __('Action') }}</span>
                        </div>

                        <div class="data-stack" id="country-table-body" style="--stack-cols: {{ $stackCols }}">
                            @include('admin.country.partials._country_table_body', [ 'countries' => $countries, ])
                        </div>

                        <div id="pagination-wrapper">
                            {{ $countries->links() }}
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
@push('scripts')
    <script>
        $(document).ready(function() {
            setupAjaxSearch({
                inputSelector: '#countrySearchInput',
                tableBodySelector: '#country-table-body',
                paginationWrapperSelector: '#pagination-wrapper',
                url: "{{ route('admin.country.search') }}",
                // Card rows, not a table: the helper's default
                // <tr><td colspan> failure message would be stray
                // markup here.
                errorHtml: '<div class="stack-empty text-danger">Error during search</div>'
            });
        });
    </script>
@endpush
