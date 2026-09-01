@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Item Categories') }}</h5>
        @if (canDo('item_category.create'))
            <a href="{{ route('admin.item_category.create') }}" class="badge alert-info primary-background-color">
                <i class="fa fa-plus"></i> {{ __('Add Item Category') }}
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
                                <input type="text" id="itemcategorySearchInput" class="form-control"
                                    placeholder="{{ __('Search...') }}">
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-borderless table-striped" id="table_list">
                                <thead class="table-light">
                                    <tr>
                                        <th>{{ __('Image') }}</th>
                                        <th>{{ __('ID') }}</th>
                                        <th>{{ __('Name') }}</th>
                                        <th>{{ __('Items') }}</th>
                                        <th>{{ __('Order') }}</th>
                                        <th>{{ __('Status') }}</th>
                                        <th class="text-center">{{ __('Action') }}</th>
                                    </tr>
                                </thead>
                                <tbody id="itemcategory-table-body">
                                    @include('admin.item_category.partials._item_category_table_body', ['itemCategories' => $itemCategories])
                                </tbody>
                            </table>
                        </div>

                        <div id="pagination-wrapper">
                            {{ $itemCategories->withQueryString()->links() }}
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
                inputSelector: '#itemcategorySearchInput',
                tableBodySelector: '#itemcategory-table-body',
                paginationWrapperSelector: '#pagination-wrapper',
                url: "{{ route('admin.item_category.search') }}",
                colspan: 7
            });
        });
    </script>
@endpush
