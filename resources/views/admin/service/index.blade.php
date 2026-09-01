@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Services') }}</h5>
        @if (canDo('service.create'))
            <a href="{{ route('admin.service.create') }}" class="badge alert-info primary-background-color">
                <i class="fa fa-plus"></i> {{ __('Add Service') }}
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
                                <input type="text" id="serviceSearchInput" class="form-control"
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
                                        <th>{{ __('Pricing') }}</th>
                                        <th>{{ __('Duration') }}</th>
                                        <th>{{ __('Order') }}</th>
                                        <th>{{ __('Status') }}</th>
                                        <th class="text-center">{{ __('Action') }}</th>
                                    </tr>
                                </thead>
                                <tbody id="service-table-body">
                                    @include('admin.service.partials._service_table_body', ['services' => $services])
                                </tbody>
                            </table>
                        </div>

                        <div id="pagination-wrapper">
                            {{ $services->withQueryString()->links() }}
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
                inputSelector: '#serviceSearchInput',
                tableBodySelector: '#service-table-body',
                paginationWrapperSelector: '#pagination-wrapper',
                url: "{{ route('admin.service.search') }}",
                colspan: 8
            });
        });
    </script>
@endpush
