@extends('layouts.main')


@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('City') }}</h5>
        @if (canDo('city.create'))
        <a href="{{ route('admin.city.create') }}" class="btn-add">
            <i class="fa fa-plus"></i>{{ __('Add City') }}
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
                                <input type="text" id="citySearchInput" name="citySearch"
                                    value="{{ request('citySearch') }}" class="form-control" placeholder="Search City...">
                            </div>
                        </div>
                        <div id="search-info" class="mb-3 small text-muted text-end" style="display: none;"></div>

                        @php
                            // Shared by the label strip and every row, so the labels
                            // sit exactly over the fields they name.
                            $stackCols = 'minmax(9rem,1.4fr) minmax(8rem,1fr) minmax(7rem,auto) minmax(8rem,1fr) minmax(6rem,auto)';
                        @endphp

                        <div class="stack-head" style="--stack-cols: {{ $stackCols }}">
                            <span>{{ __('Name') }}</span>
                            <span>{{ __('Country Name') }}</span>
                            <span>{{ __('Status') }}</span>
                            <span>{{ __('Created At') }}</span>
                            <span class="text-end">{{ __('Action') }}</span>
                        </div>

                        <div class="data-stack" id="city-table-body" style="--stack-cols: {{ $stackCols }}">
                            @include('admin.city.partials._city_table_body', [ 'cities' => $cities, ])
                        </div>

                        <div id="pagination-wrapper">
                            {{ $cities->links() }}
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
                inputSelector: '#citySearchInput',
                tableBodySelector: '#city-table-body',
                paginationWrapperSelector: '#pagination-wrapper',
                url: "{{ route('admin.city.search') }}",
                // Card rows, not a table: the helper's default
                // <tr><td colspan> failure message would be stray
                // markup here.
                errorHtml: '<div class="stack-empty text-danger">Error during search</div>'
            });
        });
    </script>
@endpush
