@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Drivers') }}</h5>
        @if (canDo('driver.create'))
            <a href="{{ route('admin.driver.create') }}" class="badge alert-info primary-background-color">
                <i class="fa fa-plus"></i> {{ __('Add Driver') }}
            </a>
        @endif
    </div>

    <section class="section">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <p class="text-muted small">
                            {{ __('Driver accounts are created here. There is no self-registration in the driver app.') }}
                        </p>

                        <div class="d-flex justify-content-end mb-3">
                            <div class="input-group" style="max-width: 350px;">
                                <input type="text" id="driverSearchInput" class="form-control"
                                    placeholder="{{ __('Search by name or phone...') }}">
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
                                        <th>{{ __('Vehicle') }}</th>
                                        <th>{{ __('Shift') }}</th>
                                        <th>{{ __('Areas') }}</th>
                                        <th>{{ __('Availability') }}</th>
                                        <th>{{ __('Status') }}</th>
                                        <th class="text-center">{{ __('Action') }}</th>
                                    </tr>
                                </thead>
                                <tbody id="driver-table-body">
                                    @include('admin.driver.partials._driver_table_body', ['drivers' => $drivers])
                                </tbody>
                            </table>
                        </div>

                        <div id="pagination-wrapper">
                            {{ $drivers->withQueryString()->links() }}
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
                inputSelector: '#driverSearchInput',
                tableBodySelector: '#driver-table-body',
                paginationWrapperSelector: '#pagination-wrapper',
                url: "{{ route('admin.driver.search') }}",
                colspan: 10
            });
        });
    </script>
@endpush
