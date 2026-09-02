@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Drivers') }}</h5>
        @if (canDo('driver.create'))
            <a href="{{ route('admin.driver.create') }}" class="btn-add">
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
                        @php
                            // Shared by the label strip and every row, so the labels
                            // sit exactly over the fields they name.
                            $stackCols = 'minmax(3.8rem,4rem) minmax(9rem,1.3fr) minmax(7rem,.9fr) minmax(8rem,1.1fr) minmax(7rem,auto) minmax(7rem,auto) minmax(6rem,auto)';
                        @endphp

                        <div class="stack-head" style="--stack-cols: {{ $stackCols }}">
                            <span>{{ __('Image') }}</span>
                            <span>{{ __('Name') }}</span>
                            <span>{{ __('Vehicle') }}</span>
                            <span>{{ __('Areas') }}</span>
                            <span>{{ __('Availability') }}</span>
                            <span>{{ __('Status') }}</span>
                            <span class="text-end">{{ __('Action') }}</span>
                        </div>

                        <div class="data-stack" id="driver-table-body" style="--stack-cols: {{ $stackCols }}">
                            @include('admin.driver.partials._driver_table_body', ['drivers' => $drivers])
                        </div>

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
                // Card rows, not a table: the helper's default
                // <tr><td colspan> failure message would be stray
                // markup here.
                errorHtml: '<div class="stack-empty text-danger">Error during search</div>'
            });
        });
    </script>
@endpush
