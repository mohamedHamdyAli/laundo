@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Order Settlements') }}</h5>
    </div>

    <section class="section">
        <div class="row mb-3">
            <div class="col-md-3">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1">{{ __('Waiting to settle') }}</h6>
                    <h3 class="mb-0 {{ $summary['pending_count'] > 0 ? 'text-attention' : '' }}">
                        {{ moneyFormat($summary['pending_laundry']) }}
                    </h3>
                    {{-- Recorded when the price was agreed, paid when the clothes
                         arrive. An order that is still pending long after it
                         completed is one whose payee could not be found. --}}
                    <small class="text-muted">
                        {{ trans_choice(':count order|:count orders', $summary['pending_count'], ['count' => $summary['pending_count']]) }}
                        · {{ __('paid once the order completes') }}
                    </small>
                </div></div>
            </div>
            <div class="col-md-3">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1">{{ $isTenant ? __('Your share this month') : __('Laundries this month') }}</h6>
                    <h3 class="mb-0">{{ moneyFormat($summary['laundry_month']) }}</h3>
                    <small class="text-muted">{{ __('Credited to the wallet') }}</small>
                </div></div>
            </div>
            <div class="col-md-3">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1">{{ __('Commission this month') }}</h6>
                    <h3 class="mb-0">{{ moneyFormat($summary['commission_month']) }}</h3>
                    <small class="text-muted">{{ __('The platform\'s share') }}</small>
                </div></div>
            </div>
            <div class="col-md-3">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1">{{ __('Commission all time') }}</h6>
                    <h3 class="mb-0">{{ moneyFormat($summary['commission_total']) }}</h3>
                    <small class="text-muted">{{ __('Settled orders only') }}</small>
                </div></div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-end mb-3 gap-2">
                    <select id="settlementStatusFilter" class="form-select" style="max-width: 220px;">
                        <option value="all" @selected($status === 'all')>{{ __('All') }}</option>
                        <option value="pending" @selected($status === 'pending')>{{ __('Pending') }}</option>
                        <option value="settled" @selected($status === 'settled')>{{ __('Settled') }}</option>
                        <option value="cancelled" @selected($status === 'cancelled')>{{ __('Cancelled') }}</option>
                    </select>
                    <div class="input-group" style="max-width: 320px;">
                        <input type="text" id="settlementSearchInput" class="form-control"
                            placeholder="{{ __('Search by order code...') }}">
                    </div>
                </div>

                <div class="table-responsive">
                    @php
                        // Shared by the label strip and every row, so the labels sit
                        // exactly over the fields they name. A laundry sees one
                        // column fewer — its own name on every row says nothing —
                        // so the track list has to shrink with the header.
                        $stackCols = $isTenant
                            ? 'minmax(8rem,1fr) minmax(9rem,1.2fr) minmax(7rem,auto) minmax(8rem,auto) minmax(7rem,auto)'
                            : 'minmax(8rem,1fr) minmax(9rem,1.2fr) minmax(9rem,1.2fr) minmax(7rem,auto) minmax(8rem,auto) minmax(7rem,auto)';
                    @endphp

                    <div class="stack-head" style="--stack-cols: {{ $stackCols }}">
                        <span>{{ __('Order') }}</span>
                        @unless ($isTenant)
                            <span>{{ __('Laundry') }}</span>
                        @endunless
                        <span>{{ __('Basis') }}</span>
                        <span>{{ __('Commission') }}</span>
                        <span>{{ __('Status') }}</span>
                        <span class="text-end">{{ $isTenant ? __('Your share') : __('Laundry share') }}</span>
                    </div>

                    <div class="data-stack" id="settlement-table-body" style="--stack-cols: {{ $stackCols }}">
                        @include('admin.settlement.partials._settlement_table_body', [
                            'settlements' => $settlements,
                            'isTenant' => $isTenant,
                        ])
                    </div>
                </div>

                <div id="pagination-wrapper">
                    {{ $settlements->withQueryString()->links() }}
                </div>
            </div>
        </div>
    </section>
@endsection

@push('scripts')
    <script>
        $(document).ready(function () {
            setupAjaxSearch({
                inputSelector: '#settlementSearchInput',
                tableBodySelector: '#settlement-table-body',
                paginationWrapperSelector: '#pagination-wrapper',
                url: "{{ route('admin.settlement.search') }}",
                // Read per request, not once at page load, so the filter
                // survives typing — the same reason the earnings screen passes a
                // function here.
                extraParams: () => ({
                    status: $('#settlementStatusFilter').val(),
                }),
                // Card rows, not a table: the helper's default
                // <tr><td colspan> failure message would be stray markup here.
                errorHtml: '<div class="stack-empty text-danger">Error during search</div>'
            });

            $('#settlementStatusFilter').on('change', function () {
                window.location = "{{ route('admin.settlement.index') }}?status=" + $(this).val();
            });
        });
    </script>
@endpush
