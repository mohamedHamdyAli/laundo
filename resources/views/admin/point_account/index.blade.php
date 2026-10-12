@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="card-title mb-0">{{ __('Customer points') }}</h5>

        @if (canDo('setting.update'))
            <a href="{{ route('admin.generalSetting.viewGeneralSetting') }}" class="btn-quiet">
                <i class="bi bi-sliders"></i>{{ __('Points settings') }}
            </a>
        @endif
    </div>

    <section class="section">
        @unless ($policy['enabled'])
            {{-- Said at the top, because a list of balances on a switched-off
                 programme reads as a programme that is running. --}}
            <div class="alert alert-warning">
                {{ __('The points programme is switched off: no order or hanger earns points and none can be spent. Balances are kept.') }}
            </div>
        @endunless

        <div class="row mb-3">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body">
                        <h6 class="text-muted mb-1">{{ __('Points customers can spend') }}</h6>
                        <h3 class="mb-0">{{ number_format($totals['outstanding']) }}</h3>
                        {{-- What the platform owes, at today's value of a point. --}}
                        <small class="text-muted">{{ __('Worth') }} {{ moneyFormat($totals['outstanding'] * $policy['point_value']) }}</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body">
                        <h6 class="text-muted mb-1">{{ __('Expiring soon') }}</h6>
                        <h3 class="mb-0">{{ number_format($totals['expiring']) }}</h3>
                        <small class="text-muted">
                            {{ $policy['expiry_days'] ? __('Within the reminder window') : __('Points never expire') }}
                        </small>
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
                            {{ __('Every customer who has ever held points. A balance is never edited directly — every change is a movement with a reason.') }}
                        </p>

                        <div class="list-toolbar justify-content-between align-items-start">
                            <x-spreadsheet-actions sheet="point_account" search="#pointAccountSearchInput" />
                            <input type="text" id="pointAccountSearchInput" class="form-control"
                                placeholder="{{ __('Search by name or phone...') }}">
                        </div>

                        @php
                            // Shared by the label strip and every row, so the labels
                            // sit exactly over the fields they name.
                            $stackCols = 'minmax(10rem,1.4fr) minmax(7rem,auto) minmax(9rem,1fr) minmax(8rem,auto) minmax(3rem,auto)';
                        @endphp

                        <div class="stack-head" style="--stack-cols: {{ $stackCols }}">
                            <span>{{ __('Customer') }}</span>
                            <span>{{ __('Customer reference') }}</span>
                            <span>{{ __('Reconciliation') }}</span>
                            <span class="text-end">{{ __('Points') }}</span>
                            <span class="text-end">{{ __('Action') }}</span>
                        </div>

                        <div class="data-stack" id="point-account-table-body" style="--stack-cols: {{ $stackCols }}">
                            @include('admin.point_account.partials._point_account_table_body', ['accounts' => $accounts, 'policy' => $policy])
                        </div>

                        <div id="pagination-wrapper">
                            {{ $accounts->withQueryString()->links() }}
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
                inputSelector: '#pointAccountSearchInput',
                tableBodySelector: '#point-account-table-body',
                paginationWrapperSelector: '#pagination-wrapper',
                url: "{{ route('admin.point_account.search') }}",
                // Card rows, not a table: the helper's default
                // <tr><td colspan> failure message would be stray markup here.
                errorHtml: '<div class="stack-empty text-danger">{{ __('Error during search') }}</div>'
            });
        });
    </script>
@endpush
