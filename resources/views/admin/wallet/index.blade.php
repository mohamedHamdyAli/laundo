@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Wallets') }}</h5>
    </div>

    <section class="section">
        <div class="row mb-3">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body">
                        <h6 class="text-muted mb-1">{{ __('Total held') }}</h6>
                        <h3 class="mb-0">{{ moneyFormat($totals['balance']) }}</h3>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body">
                        <h6 class="text-muted mb-1">{{ __('Pending (drivers)') }}</h6>
                        <h3 class="mb-0">{{ moneyFormat($totals['pending']) }}</h3>
                        <small class="text-muted">{{ __('Earned, not yet withdrawable') }}</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body">
                        <h6 class="text-muted mb-1">{{ __('Out of balance') }}</h6>
                        <h3 class="mb-0 {{ $totals['unreconciled'] > 0 ? 'text-danger' : 'text-success' }}">
                            {{ $totals['unreconciled'] }}
                        </h3>
                        <small class="text-muted">{{ __('Cached balance vs the ledger') }}</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <p class="text-muted small">
                            {{ __('Only wallets holding money are listed. A balance is never edited directly — every change is a transaction.') }}
                        </p>

                        <div class="d-flex justify-content-end mb-3">
                            <div class="input-group" style="max-width: 350px;">
                                <input type="text" id="walletSearchInput" class="form-control"
                                    placeholder="{{ __('Search by name or phone...') }}">
                            </div>
                        </div>

                        <div class="table-responsive">
                        @php
                            // Shared by the label strip and every row, so the labels
                            // sit exactly over the fields they name.
                            $stackCols = 'minmax(8rem,1.1fr) minmax(9rem,1.2fr) minmax(6rem,auto) minmax(7rem,.9fr) minmax(7rem,auto) minmax(3rem,auto)';
                        @endphp

                        <div class="stack-head" style="--stack-cols: {{ $stackCols }}">
                            <span>{{ __('Owner') }}</span>
                            <span>{{ __('Reconciliation') }}</span>
                            <span>{{ __('Hold') }}</span>
                            <span>{{ __('Pending') }}</span>
                            <span class="text-end">{{ __('Balance') }}</span>
                            <span class="text-end">{{ __('Action') }}</span>
                        </div>

                        <div class="data-stack" id="wallet-table-body" style="--stack-cols: {{ $stackCols }}">
                            @include('admin.wallet.partials._wallet_table_body', ['wallets' => $wallets])
                        </div>

                        </div>

                        <div id="pagination-wrapper">
                            {{ $wallets->withQueryString()->links() }}
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
                inputSelector: '#walletSearchInput',
                tableBodySelector: '#wallet-table-body',
                paginationWrapperSelector: '#pagination-wrapper',
                url: "{{ route('admin.wallet.search') }}",
                // Card rows, not a table: the helper's default
                // <tr><td colspan> failure message would be stray
                // markup here.
                errorHtml: '<div class="stack-empty text-danger">Error during search</div>'
            });
        });
    </script>
@endpush
