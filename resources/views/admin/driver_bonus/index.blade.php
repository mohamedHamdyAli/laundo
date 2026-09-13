@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Monthly Bonuses') }}</h5>
    </div>

    <section class="section">
        <div class="row mb-3">
            <div class="col-md-3">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1">{{ __('Waiting for you') }}</h6>
                    <h3 class="mb-0 {{ $summary['due_count'] > 0 ? 'text-attention' : '' }}">
                        {{ moneyFormat($summary['due_total']) }}
                    </h3>
                    <small class="text-muted">
                        {{ trans_choice(':count driver|:count drivers', $summary['due_count'], ['count' => $summary['due_count']]) }}
                        · {{ __('nothing is paid until you approve it') }}
                    </small>
                </div></div>
            </div>
            <div class="col-md-3">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1">{{ __('Approved this month') }}</h6>
                    <h3 class="mb-0">{{ moneyFormat($summary['approved_total']) }}</h3>
                    <small class="text-muted">{{ __('Added to driver wallets') }}</small>
                </div></div>
            </div>
            <div class="col-md-3">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1">{{ __('Blocked on quality') }}</h6>
                    <h3 class="mb-0 {{ $summary['gated_count'] > 0 ? 'text-attention' : '' }}">
                        {{ $summary['gated_count'] }}
                    </h3>
                    {{-- The figure worth leading with after «what is waiting»:
                         a driver who hit a target and lost it on quality is a
                         conversation, not a payment. --}}
                    <small class="text-muted">{{ __('Hit a target, missed a condition') }}</small>
                </div></div>
            </div>
            <div class="col-md-3">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1">{{ __('Month') }}</h6>
                    <h3 class="mb-0">{{ $period }}</h3>
                    <small class="text-muted">{{ __('Recalculated every time you open this') }}</small>
                </div></div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <p class="text-muted small">
                    {{ __('Worked out by the system, paid by you. An open month is recalculated on every visit; an approved one is frozen.') }}
                </p>

                <div class="d-flex justify-content-end mb-3 gap-2">
                    <select id="bonusPeriodFilter" class="form-select" style="max-width: 160px;">
                        @foreach ($months as $month)
                            <option value="{{ $month }}" @selected($period === $month)>{{ $month }}</option>
                        @endforeach
                    </select>
                    <select id="bonusStatusFilter" class="form-select" style="max-width: 200px;">
                        <option value="all" @selected($status === 'all')>{{ __('All') }}</option>
                        <option value="due" @selected($status === 'due')>{{ __('Waiting for you') }}</option>
                        <option value="approved" @selected($status === 'approved')>{{ __('Approved') }}</option>
                        <option value="rejected" @selected($status === 'rejected')>{{ __('Declined') }}</option>
                    </select>
                    <div class="input-group" style="max-width: 300px;">
                        <input type="text" id="bonusSearchInput" class="form-control"
                            placeholder="{{ __('Search by driver name or phone...') }}">
                    </div>
                </div>

                <div class="table-responsive">
                    @php
                        $stackCols = 'minmax(9rem,1.2fr) minmax(6rem,auto) minmax(9rem,1.1fr) minmax(10rem,1.3fr) minmax(8rem,auto) minmax(6rem,auto)';
                    @endphp

                    <div class="stack-head" style="--stack-cols: {{ $stackCols }}">
                        <span>{{ __('Driver') }}</span>
                        <span>{{ __('Delivered') }}</span>
                        <span>{{ __('On time') }}</span>
                        <span>{{ __('Status') }}</span>
                        <span class="text-end">{{ __('Bonus') }}</span>
                        <span class="text-end">{{ __('Action') }}</span>
                    </div>

                    <div class="data-stack" id="bonus-table-body" style="--stack-cols: {{ $stackCols }}">
                        @include('admin.driver_bonus.partials._driver_bonus_table_body', ['awards' => $awards])
                    </div>
                </div>

                <div id="pagination-wrapper">
                    {{ $awards->withQueryString()->links() }}
                </div>
            </div>
        </div>
    </section>
@endsection

@push('scripts')
    <script>
        $(document).ready(function () {
            setupAjaxSearch({
                inputSelector: '#bonusSearchInput',
                tableBodySelector: '#bonus-table-body',
                paginationWrapperSelector: '#pagination-wrapper',
                url: "{{ route('admin.driver_bonus.search') }}",
                // Read per request, not once at page load, so both filters
                // survive typing.
                extraParams: () => ({
                    period: $('#bonusPeriodFilter').val(),
                    status: $('#bonusStatusFilter').val(),
                }),
                errorHtml: '<div class="stack-empty text-danger">Error during search</div>'
            });

            // A full reload, not an AJAX swap: the four cards follow the month
            // and the status, and the search path only replaces the rows — so
            // filtering in place would leave the totals describing a different
            // set from the list under them.
            function reload() {
                window.location = "{{ route('admin.driver_bonus.index') }}"
                    + '?period=' + $('#bonusPeriodFilter').val()
                    + '&status=' + $('#bonusStatusFilter').val();
            }

            $('#bonusPeriodFilter, #bonusStatusFilter').on('change', reload);
        });
    </script>
@endpush
