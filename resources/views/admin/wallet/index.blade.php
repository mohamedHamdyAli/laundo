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
                            @if ($type)
                                {{-- Said plainly, because the two lists follow
                                     different rules and a silently different one
                                     is the kind of thing somebody reconciles
                                     against and gets wrong. --}}
                                {{ __('Every wallet in this group is listed, including the empty ones.') }}
                            @else
                                {{ __('Only wallets holding money are listed. Pick a group to see every wallet in it, empty ones included.') }}
                            @endif
                            {{ __('A balance is never edited directly — every change is a transaction.') }}
                        </p>

                        <div class="d-flex justify-content-end mb-3 gap-2">
                            <select id="walletTypeFilter" class="form-select" style="max-width: 220px;">
                                <option value="">{{ __('All wallets') }}</option>
                                @foreach ($types as $case)
                                    <option value="{{ $case->value }}" @selected($type === $case)>
                                        {{ __($case->label()) }}
                                    </option>
                                @endforeach
                            </select>
                            <div class="input-group" style="max-width: 350px;">
                                <input type="text" id="walletSearchInput" class="form-control"
                                    placeholder="{{ __('Search by name or phone...') }}">
                            </div>
                        </div>

                        <div class="table-responsive">
                        @php
                            // Shared by the label strip and every row, so the labels
                            // sit exactly over the fields they name.
                            $stackCols = 'minmax(8rem,1.1fr) minmax(6rem,auto) minmax(9rem,1.2fr) minmax(6rem,auto) minmax(7rem,.9fr) minmax(7rem,auto) minmax(3rem,auto)';
                        @endphp

                        <div class="stack-head" style="--stack-cols: {{ $stackCols }}">
                            <span>{{ __('Owner') }}</span>
                            {{-- Kept even when a group is selected: the filter
                                 sits above the fold and the column is what says
                                 which list you are looking at once you scroll. --}}
                            <span>{{ __('Type') }}</span>
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
                // Read per request, not once at page load, so the group survives
                // typing. A function, for the same reason the earnings screen
                // uses one.
                extraParams: () => ({
                    type: $('#walletTypeFilter').val(),
                }),
                // Card rows, not a table: the helper's default
                // <tr><td colspan> failure message would be stray
                // markup here.
                errorHtml: '<div class="stack-empty text-danger">Error during search</div>'
            });

            // A full reload rather than an AJAX swap: the three cards at the top
            // follow the filter, and the search path only replaces the rows —
            // so filtering in place would leave totals describing a different
            // set from the list under them.
            $('#walletTypeFilter').on('change', function () {
                const value = $(this).val();
                window.location = "{{ route('admin.wallet.index') }}" + (value ? '?type=' + value : '');
            });
        });
    </script>
@endpush
