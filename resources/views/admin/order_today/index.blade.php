@extends('layouts.main')

@push('styles')
    <style>
        /* The arrow turns when its row is open. Scoped to this screen, and inline
           rather than in theme.css, so a release does not depend on a cached
           stylesheet catching up. */
        .order-today-toggle i { transition: transform .15s ease; display: inline-block; }
        .order-today-toggle[aria-expanded="true"] i { transform: rotate(180deg); }
        .order-today-details { grid-column: 1 / -1; }
        .order-today-details dl { display: grid; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); gap: .5rem 1.5rem; margin: .75rem 0 0; }
        .order-today-details dt { font-weight: 600; font-size: .8rem; color: var(--bs-secondary-color, #6c757d); }
        .order-today-details dd { margin: 0; }
        .order-today-chips { display: flex; flex-wrap: wrap; gap: .35rem; }
        .order-today-chip { border: 1px solid var(--surface-border, #dee2e6); border-radius: 999px; padding: .1rem .6rem; font-size: .85rem; white-space: nowrap; }
        /* Every select on the panel is turned into select2 at 100% of its
           parent, so each filter sits in a cell of its own; laid straight into
           the flex toolbar, every one of them took a whole line. */
        .order-today-filters { display: grid; grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr)); gap: .75rem; margin-bottom: 1rem; }
        .order-today-filters .order-today-search { grid-column: span 2; }
        @media (max-width: 575.98px) { .order-today-filters .order-today-search { grid-column: auto; } }
        .order-today-items .order-today-count { font-size: 1.15rem; font-weight: 700; }
        .order-today-items tfoot th { border-top: 2px solid var(--surface-border, #dee2e6); }
    </style>
@endpush

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Today\'s orders') }}</h5>
    </div>

    <section class="section">
        <div class="card">
            <div class="card-body">
                {{-- The filters. Every control redraws the totals and the list
                     together, because the totals are summed over exactly the
                     rows below them. --}}
                <div class="order-today-filters" id="order-today-filters">
                    <div>
                        <select id="orderTodayScope" class="form-select" aria-label="{{ __('Show') }}">
                            @foreach ($scopes as $scope)
                                <option value="{{ $scope }}" @selected($filters['scope'] === $scope)>
                                    {{ match ($scope) {
                                        'in_laundry' => __('In the laundry now'),
                                        'delivery_today' => __('Due for delivery on'),
                                        'pickup_today' => __('Due for pickup on'),
                                    } }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Only the two dated scopes read it; «in the laundry now»
                         is now by definition. --}}
                    <div id="orderTodayDateCell" @if ($filters['scope'] === 'in_laundry') hidden @endif>
                        <input type="date" id="orderTodayDate" class="form-control"
                            value="{{ $filters['date'] }}" aria-label="{{ __('Date') }}">
                    </div>

                    <div>
                        <select id="orderTodayStatus" class="form-select" aria-label="{{ __('Status') }}">
                            <option value="">{{ __('All active statuses') }}</option>
                            @foreach ($statuses as $status)
                                <option value="{{ $status->value }}" @selected($filters['status'] === $status->value)>
                                    {{ __($status->label()) }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <select id="orderTodayService" class="form-select" aria-label="{{ __('Service') }}">
                            <option value="">{{ __('All services') }}</option>
                            @foreach ($services as $service)
                                <option value="{{ $service->id }}" @selected($filters['service_id'] === $service->id)>
                                    {{ getLocalizedValueDashboard($service, 'name') }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    @if ($laundries->isNotEmpty())
                        <div>
                            <select id="orderTodayLaundry" class="form-select" aria-label="{{ __('Laundry') }}">
                                <option value="">{{ __('All laundries') }}</option>
                                @foreach ($laundries as $laundry)
                                    <option value="{{ $laundry->id }}" @selected($filters['laundry_id'] === $laundry->id)>
                                        {{ getLocalizedValueDashboard($laundry, 'name') }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    <div class="order-today-search">
                        <input type="text" id="orderTodaySearch" class="form-control"
                            placeholder="{{ __('Order code, customer or phone...') }}" autocomplete="off"
                            value="{{ $filters['query'] }}">
                    </div>
                </div>

                <div id="order-today-summary">
                    @include('admin.order_today.partials._order_today_summary')
                </div>

                @php
                    $stackCols = 'minmax(8rem,1fr) minmax(9rem,1.1fr) minmax(10rem,1.5fr) minmax(9rem,1fr) auto';
                @endphp

                <div class="stack-head" style="--stack-cols: {{ $stackCols }}">
                    <span>{{ __('Order') }}</span>
                    <span>{{ __('Status') }}</span>
                    <span>{{ __('Pieces') }}</span>
                    <span>{{ __('When') }}</span>
                    <span class="text-end">{{ __('Details') }}</span>
                </div>

                <div class="data-stack" id="order-today-table-body" style="--stack-cols: {{ $stackCols }}">
                    @include('admin.order_today.partials._order_today_table_body')
                </div>
            </div>
        </div>
    </section>
@endsection

@push('scripts')
    <script>
        $(document).ready(function () {
            const url = @json(route('admin.order_today.search'));
            let timer = null;
            let pending = null;

            // Read at request time, never captured at load: the controls compose
            // rather than the last one touched reverting the others.
            function params() {
                return {
                    scope: $('#orderTodayScope').val(),
                    date: $('#orderTodayDate').val(),
                    status: $('#orderTodayStatus').val(),
                    service_id: $('#orderTodayService').val(),
                    laundry_id: $('#orderTodayLaundry').val() || '',
                    query: $('#orderTodaySearch').val(),
                };
            }

            function reload() {
                if (pending) pending.abort();

                pending = $.ajax({ url: url, data: params(), headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .done(function (response) {
                        $('#order-today-summary').html(response.summary);
                        $('#order-today-table-body').html(response.table);
                    })
                    .fail(function (xhr) {
                        if (xhr.statusText === 'abort') return;
                        $('#order-today-table-body').html('<div class="stack-empty text-danger">{{ __('Error during search') }}</div>');
                    });
            }

            $('#orderTodayScope').on('change', function () {
                $('#orderTodayDateCell').prop('hidden', $(this).val() === 'in_laundry');
                reload();
            });

            $('#orderTodayDate, #orderTodayStatus, #orderTodayService, #orderTodayLaundry').on('change', reload);

            // keyup, as on every other list here; debounced so a code typed at
            // speed is one request, not eight.
            $('#orderTodaySearch').on('keyup', function () {
                clearTimeout(timer);
                timer = setTimeout(reload, 300);
            });
        });
    </script>
@endpush
