@extends('layouts.main')


@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Country') }}</h5>
        <a href="{{ route('admin.country.create') }}" class="badge alert-info primary-background-color">
            <i class="fa fa-plus"></i>{{ __('Add Country') }}
        </a>
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

                        <table class="table table-borderless table-striped" id="table_list">
                            <thead class="table-light">
                                <tr>
                                    <th>{{ __('ID') }}</th>
                                    <th>{{ __('Name') }}</th>
                                    <th>{{ __('Code') }}</th>
                                    <th>{{ __('Phone Code') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th>{{ __('Created At') }}</th>
                                    <th class="text-center">{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody id="country-table-body">
                                @include('admin.country.partials._country_table_body', [
                                    'countries' => $countries,
                                ])
                            </tbody>
                        </table>

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
                colspan: 8
            });
        });
    </script>
@endpush
