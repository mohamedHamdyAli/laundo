@extends('layouts.main')


@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('City') }}</h5>
        <a href="{{ route('admin.city.create') }}" class="badge alert-info primary-background-color">
            <i class="fa fa-plus"></i>{{ __('Add City') }}
        </a>
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

                        <table class="table table-borderless table-striped" id="table_list">
                            <thead class="table-light">
                                <tr>
                                    <th>{{ __('ID') }}</th>
                                    <th>{{ __('Name') }}</th>
                                    <th>{{ __('Country Name') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th>{{ __('Created At') }}</th>
                                    <th class="text-center">{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody id="city-table-body">
                                @include('admin.city.partials._city_table_body', [
                                    'cities' => $cities,
                                ])
                            </tbody>
                        </table>

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
                colspan: 8
            });
        });
    </script>
@endpush
