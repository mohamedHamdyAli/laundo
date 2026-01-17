@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Categories') }}</h5>
        @if (canDo('category.create'))
            <a href="{{ route('admin.category.create') }}" class="btn btn-primary btn-bg">
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

                        <table class="table table-bordered table-striped align-middle text-center" id="table_list">
                            <thead class="thead-dark">
                                <tr>
                                    <th>{{ __('ID') }}</th>
                                    <th>{{ __('Image') }}</th>
                                    <th>{{ __('Name') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th>{{ __('Created At') }}</th>
                                    <th class="text-center">{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody id="category-table-body">
                                @include('admin.category.partials._category_table_body', [
                                    'categories' => $categories,
                                ])
                            </tbody>
                        </table>

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
                colspan: 8
            });
        });
    </script>
@endpush
