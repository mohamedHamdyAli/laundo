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

                                {{-- The two the home page's queue links to, and
                                     the reason they are here rather than in
                                     `OrderStatus`: neither is a status. A
                                     driverless journey is a *task* state — the
                                     order still reads «Awaiting pickup» while one
                                     of its legs waits in the pool — and «no
                                     laundry» is a null `laundry_id`.

                                     They belong in the dropdown all the same, or
                                     an operator arriving from the queue sees a
                                     filtered list above a box that claims to be
                                     showing all statuses. --}}
                                <optgroup label="{{ __('Waiting for a person') }}">
                                    <option value="{{ $needsDriver }}" @selected(($activeStatus ?? null) === $needsDriver)>
                                        {{ __('Has a journey with no driver') }}
                                    </option>
                                    <option value="{{ $needsLaundry }}" @selected(($activeStatus ?? null) === $needsLaundry)>
                                        {{ __('No laundry yet') }}
                                    </option>
                                    <option value="{{ $needsRescue }}" @selected(($activeStatus ?? null) === $needsRescue)>
                                        {{ __('Has a journey that ran out of attempts') }}
                                    </option>
                                    <option value="{{ $needsPriceAnswer }}" @selected(($activeStatus ?? null) === $needsPriceAnswer)>
                                        {{ __('Has an unanswered price question') }}
                                    </option>
                                </optgroup>

                                <optgroup label="{{ __('By status') }}">
                                    @foreach ($statuses as $status)
                                        <option value="{{ $status->value }}"
                                            @selected(($activeStatus ?? null) === $status->value)>
                                            {{ __($status->label()) }}
                                        </option>
                                    @endforeach
                                </optgroup>
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
                            $stackCols = 'minmax(8rem,1.1fr) minmax(9rem,1.3fr) minmax(8rem,1fr) minmax(9rem,1.1fr) minmax(6rem,auto) 1.5rem';
                        @endphp

                        <div class="stack-head" style="--stack-cols: {{ $stackCols }}">
                            <span>{{ __('Order') }}</span>
                            <span>{{ __('Customer') }}</span>
                            <span>{{ __('Service') }}</span>
                            <span>{{ __('Status') }}</span>
                            <span class="text-end">{{ __('Total') }}</span>
                            {{-- The chevron's column. Empty and hidden from
                                 assistive tech: it labels nothing, it only has
                                 to hold the track open so the five real labels
                                 stay over the fields they name. --}}
                            <span aria-hidden="true"></span>
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
                // Keeps the filter when somebody types. The controller reads it from
                // the same request, and without this it silently reverted to the
                // default. A function, so the value is read per request rather than
                // once at page load.
                extraParams: () => ({
                    status: $('#orderStatusFilter').val(),
                }),
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
