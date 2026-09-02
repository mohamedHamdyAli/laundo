@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Orders') }}</h5>
    </div>

    <section class="section">
        <div class="row mb-3">
            <div class="col-md-4">
                {{-- h-100 on all three: «Unassigned» carries a subtitle the
                     other two do not, and without it that card stood 21px
                     taller than the pair beside it. --}}
                <div class="card h-100 stat-card">
                    <div class="card-body">
                        <h6 class="text-muted mb-1">{{ __('All Orders') }}</h6>
                        <h3 class="mb-0">{{ $counts['total'] }}</h3>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card h-100 stat-card">
                    <div class="card-body">
                        <h6 class="text-muted mb-1">{{ __('Active') }}</h6>
                        <h3 class="mb-0">{{ $counts['active'] }}</h3>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card h-100 stat-card">
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
                        {{-- Search leads because it is the control people
                             reach for; the filter narrows what it searches.
                             Pushed to the far end, the pair left 274px of empty
                             card to their left and read as floating. --}}
                        <div class="list-toolbar">
                            <input type="text" id="orderSearchInput" class="form-control list-toolbar-search"
                                placeholder="{{ __('Search by order code, customer or phone...') }}">
                            <select id="orderStatusFilter" class="form-select list-toolbar-filter">
                                <option value="">{{ __('All statuses') }}</option>
                                @foreach ($statuses as $status)
                                    <option value="{{ $status->value }}"
                                        @selected(($activeStatus ?? null) === $status->value)>
                                        {{ __($status->label()) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        {{-- Direction «Stack»: row-cards, not a table.

                             `--stack-cols` is declared once here and shared by
                             the label strip and every row, so the labels sit
                             exactly over the fields they name. The container
                             keeps the `order-table-body` id because the shared
                             AJAX helper targets it by that name and replaces its
                             HTML wholesale. --}}
                        @php
                            $stackCols = 'minmax(8rem,1.1fr) minmax(9rem,1.3fr) minmax(8rem,1fr) minmax(9rem,1.1fr) minmax(6rem,auto)';
                        @endphp

                        <div class="stack-head" style="--stack-cols: {{ $stackCols }}">
                            <span>{{ __('Order') }}</span>
                            <span>{{ __('Customer') }}</span>
                            <span>{{ __('Service') }}</span>
                            <span>{{ __('Status') }}</span>
                            <span class="text-end">{{ __('Total') }}</span>
                        </div>

                        <div class="data-stack" id="order-table-body" style="--stack-cols: {{ $stackCols }}">
                            @include('admin.order.partials._order_table_body', ['orders' => $orders])
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
                // This list is card rows, not a table, so the helper's default
                // `<tr><td colspan>` failure message would be stray markup here.
                errorHtml: '<div class="stack-empty text-danger">{{ __('Error during search') }}</div>'
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
