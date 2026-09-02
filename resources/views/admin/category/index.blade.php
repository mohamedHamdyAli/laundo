@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Categories') }}</h5>
        @if (canDo('category.create'))
            <a href="{{ route('admin.category.create') }}" class="btn-add">
                <i class="fa fa-plus"></i> {{ __('Add Category') }}
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
                                <input type="text" id="categorySearchInput" name="categorySearch"
                                    value="{{ request('categorySearch') }}" class="form-control"
                                    placeholder="{{ __('Search category...') }}">
                            </div>
                        </div>
                        <div id="search-info" class="mb-3 small text-muted text-end" style="display: none;"></div>

                        @php
                            // Shared by the label strip and every row, so the labels
                            // sit exactly over the fields they name.
                            $stackCols = 'minmax(3.8rem,4rem) minmax(9rem,1.3fr) minmax(8rem,1fr) minmax(7rem,auto) minmax(8rem,1fr) minmax(6rem,auto)';
                        @endphp

                        <div class="stack-head" style="--stack-cols: {{ $stackCols }}">
                            <span>{{ __('Image') }}</span>
                            <span>{{ __('Name') }}</span>
                            <span>{{ __('Subcategories') }}</span>
                            <span>{{ __('Status') }}</span>
                            <span>{{ __('Created At') }}</span>
                            <span class="text-end">{{ __('Action') }}</span>
                        </div>

                        <div class="data-stack" id="category-table-body" style="--stack-cols: {{ $stackCols }}">
                            @include('admin.category.partials._category_table_body', [ 'categories' => $categories, ])
                        </div>

                        <div id="pagination-wrapper">
                            {{ $categories->withQueryString()->links() }}
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
                inputSelector: '#categorySearchInput',
                tableBodySelector: '#category-table-body',
                paginationWrapperSelector: '#pagination-wrapper',
                url: "{{ route('admin.category.search') }}",
                // Card rows, not a table: the helper's default
                // <tr><td colspan> failure message would be stray
                // markup here.
                errorHtml: '<div class="stack-empty text-danger">Error during search</div>'
            });
        });
    </script>
@endpush
