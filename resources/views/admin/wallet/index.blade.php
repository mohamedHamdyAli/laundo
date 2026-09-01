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
                            <table class="table table-borderless table-striped" id="table_list">
                                <thead class="table-light">
                                    <tr>
                                        <th>{{ __('Owner') }}</th>
                                        <th>{{ __('Balance') }}</th>
                                        <th>{{ __('Pending') }}</th>
                                        <th>{{ __('Ledger') }}</th>
                                        <th>{{ __('State') }}</th>
                                        <th class="text-center">{{ __('Action') }}</th>
                                    </tr>
                                </thead>
                                <tbody id="wallet-table-body">
                                    @include('admin.wallet.partials._wallet_table_body', ['wallets' => $wallets])
                                </tbody>
                            </table>
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
                colspan: 6
            });
        });
    </script>
@endpush
