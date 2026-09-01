@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Orders') }}</h5>
    </div>

    <section class="section">
        <div class="row mb-3">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body">
                        <h6 class="text-muted mb-1">{{ __('All Orders') }}</h6>
                        <h3 class="mb-0">{{ $counts['total'] }}</h3>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body">
                        <h6 class="text-muted mb-1">{{ __('Active') }}</h6>
                        <h3 class="mb-0">{{ $counts['active'] }}</h3>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body">
                        <h6 class="text-muted mb-1">{{ __('Unassigned') }}</h6>
                        <h3 class="mb-0 {{ $counts['unassigned'] > 0 ? 'text-warning' : '' }}">
                            {{ $counts['unassigned'] }}
                        </h3>
                        <small class="text-muted">{{ __('Waiting to be given to a laundry') }}</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-end mb-3 gap-2">
                            <select id="orderStatusFilter" class="form-select" style="max-width: 220px;">
                                <option value="">{{ __('All statuses') }}</option>
                                @foreach ($statuses as $status)
                                    <option value="{{ $status->value }}"
                                        @selected(($activeStatus ?? null) === $status->value)>
                                        {{ __($status->label()) }}
                                    </option>
                                @endforeach
                            </select>
                            <div class="input-group" style="max-width: 350px;">
                                <input type="text" id="orderSearchInput" class="form-control"
                                    placeholder="{{ __('Search by order code, customer or phone...') }}">
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-borderless table-striped" id="table_list">
                                <thead class="table-light">
                                    <tr>
                                        <th>{{ __('Order') }}</th>
                                        <th>{{ __('Customer') }}</th>
                                        <th>{{ __('Service') }}</th>
                                        <th>{{ __('Laundry') }}</th>
                                        <th>{{ __('Status') }}</th>
                                        <th>{{ __('Total') }}</th>
                                        <th>{{ __('Pickup') }}</th>
                                        <th>{{ __('Placed') }}</th>
                                        <th class="text-center">{{ __('Action') }}</th>
                                    </tr>
                                </thead>
                                <tbody id="order-table-body">
                                    @include('admin.order.partials._order_table_body', ['orders' => $orders])
                                </tbody>
                            </table>
                        </div>

                        <div id="pagination-wrapper">
                            {{ $orders->withQueryString()->links() }}
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
                inputSelector: '#orderSearchInput',
                tableBodySelector: '#order-table-body',
                paginationWrapperSelector: '#pagination-wrapper',
                url: "{{ route('admin.order.search') }}",
                colspan: 9
            });

            // The status filter reuses the same search endpoint, which reads
            // `status` alongside `query` — so filter and term compose instead of
            // overriding one another.
            $('#orderStatusFilter').on('change', function () {
                $.get("{{ route('admin.order.search') }}", {
                    query: $('#orderSearchInput').val(),
                    status: $(this).val()
                }, function (response) {
                    $('#order-table-body').html(response.table);
                    $('#pagination-wrapper').html(response.pagination);
                });
            });
        });
    </script>
@endpush
