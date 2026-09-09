@extends('layouts.main')
@section('content')
    {{--
        Every journey waiting for a person, across every order.

        Before this the only place a leg could be given a driver was inside its
        own order's page: filter the order list to «has a journey with no
        driver», open an order, read its Transport table, assign, go back, open
        the next. The home page counted fifteen waiting journeys and reaching
        them meant fifteen round trips through four orders.
    --}}
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="card-title mb-0">{{ __('Dispatch') }}</h5>

        @if (canDo('order.update') && $counts['never_taken'] > 0)
            {{-- The scheduled sweep runs every ten minutes, which is no use to
                 somebody who has just given a driver a zone or raised a cap. --}}
            <form method="POST" action="{{ route('admin.dispatch.redispatch') }}">
                @csrf
                <button type="submit" class="btn-quiet">
                    <i class="bi bi-arrow-repeat"></i>{{ __('Try them all again') }}
                </button>
            </form>
        @endif
    </div>

    <section class="section">
        {{-- Two numbers, because they are two different problems: a leg nobody
             has taken needs a driver, and a leg that failed needs somebody to
             find out what happened. The third says how many orders they belong
             to — the count is legs, and «15» over a list of 6 orders is what
             made this confusing in the first place. --}}
        <div class="row mb-3">
            <div class="col-md-4">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1 small">{{ __('Waiting for a driver') }}</h6>
                    <h3 class="mb-0 {{ $counts['never_taken'] > 0 ? 'text-attention' : 'text-success' }}">
                        {{ $counts['never_taken'] }}
                    </h3>
                </div></div>
            </div>
            <div class="col-md-4">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1 small">{{ __('Failed and needing a person') }}</h6>
                    <h3 class="mb-0 {{ $counts['failed'] > 0 ? 'text-danger' : 'text-success' }}">
                        {{ $counts['failed'] }}
                    </h3>
                </div></div>
            </div>
            <div class="col-md-4">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1 small">{{ __('Across this many orders') }}</h6>
                    <h3 class="mb-0">{{ $counts['orders'] }}</h3>
                </div></div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <div class="list-toolbar">
                            <input type="text" id="dispatchSearchInput" class="form-control list-toolbar-search"
                                placeholder="{{ __('Search by order code, customer or phone...') }}"
                                autocomplete="off">

                            <select id="dispatchLegFilter" class="form-select list-toolbar-filter">
                                <option value="">{{ __('Every leg') }}</option>
                                @foreach ($legTypes as $type)
                                    <option value="{{ $type->value }}" @selected($activeLeg === $type->value)>
                                        {{ __($type->label()) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        @php
                            // Shared by the label strip and every row, so the
                            // labels sit over the fields they name.
                            $stackCols = 'minmax(7rem,auto) minmax(9rem,1fr) minmax(9rem,1fr) minmax(7rem,auto) minmax(5rem,auto) minmax(16rem,1.4fr)';
                        @endphp

                        <div class="stack-head" style="--stack-cols: {{ $stackCols }}">
                            <span>{{ __('Order') }}</span>
                            <span>{{ __('Leg') }}</span>
                            <span>{{ __('Customer') }}</span>
                            {{-- The field that decides who is eligible, so it is
                                 on the row rather than two clicks away. --}}
                            <span>{{ __('Area') }}</span>
                            <span>{{ __('Waiting') }}</span>
                            <span class="text-end">{{ __('Give it to') }}</span>
                        </div>

                        <div class="data-stack" id="dispatch-table-body" style="--stack-cols: {{ $stackCols }}">
                            @include('admin.dispatch.partials._dispatch_table_body')
                        </div>

                        <div id="pagination-wrapper">
                            {{ $legs->withQueryString()->links() }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection

@push('scripts')
    <script>
        setupAjaxSearch({
            inputSelector: '#dispatchSearchInput',
            tableBodySelector: '#dispatch-table-body',
            paginationWrapperSelector: '#pagination-wrapper',
            url: '{{ route('admin.dispatch.search') }}',
            errorHtml: '<div class="stack-empty">{{ __('Error during search') }}</div>',
            // Read per request, not captured at load, so the leg filter and the
            // term compose instead of the term reverting the filter.
            extraParams: () => ({
                leg: $('#dispatchLegFilter').val(),
            }),
        });

        $('#dispatchLegFilter').on('change', function() {
            $.get('{{ route('admin.dispatch.search') }}', {
                query: $('#dispatchSearchInput').val(),
                leg: $(this).val(),
            }, function(response) {
                $('#dispatch-table-body').html(response.table);
                $('#pagination-wrapper').html(response.pagination);
            });
        });
    </script>
@endpush
