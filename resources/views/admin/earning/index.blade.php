@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Driver Earnings') }}</h5>
    </div>

    <section class="section">
        <div class="row mb-3">
            <div class="col-md-3">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1">{{ __('Owed right now') }}</h6>
                    <h3 class="mb-0 {{ $summary['pending'] > 0 ? 'text-attention' : '' }}">
                        {{ moneyFormat($summary['pending']) }}
                    </h3>
                    {{-- «الرصيد المعلق» — earned on a completed leg and not yet
                         withdrawable, because the order can still be returned. --}}
                    <small class="text-muted">{{ __('Earned, held until the order completes') }}</small>
                </div></div>
            </div>
            <div class="col-md-3">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1">{{ __('Drivers waiting') }}</h6>
                    <h3 class="mb-0">{{ $summary['drivers_owed'] }}</h3>
                    <small class="text-muted">{{ __('People with something held') }}</small>
                </div></div>
            </div>
            <div class="col-md-3">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1">{{ __('Released this month') }}</h6>
                    <h3 class="mb-0">{{ moneyFormat($summary['released_month']) }}</h3>
                    <small class="text-muted">{{ __('Became withdrawable') }}</small>
                </div></div>
            </div>
            <div class="col-md-3">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1">{{ __('Released all time') }}</h6>
                    <h3 class="mb-0">{{ moneyFormat($summary['released']) }}</h3>
                    {{-- Paying a driver out happens outside the app, so this is
                         what became available and not what left the bank. --}}
                    <small class="text-muted">{{ __('Not the same as paid out') }}</small>
                </div></div>
            </div>
        </div>

        @if (count($byDriver) > 0)
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">{{ __('Owed, by driver') }}</h6>
                    {{-- The question operations actually has. Before this screen it
                         was answerable only from the driver's own app. --}}
                    <small class="text-muted">{{ __('Most owed first') }}</small>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-borderless mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>{{ __('Driver') }}</th>
                                    <th>{{ __('Journeys') }}</th>
                                    <th class="text-end">{{ __('Owed') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($byDriver as $entry)
                                    <tr>
                                        <td>
                                            {{ $entry['driver'] }}
                                            <small class="text-muted d-block">{{ $entry['phone'] }}</small>
                                        </td>
                                        <td>{{ $entry['legs'] }}</td>
                                        <td class="text-end"><strong>{{ moneyFormat($entry['owed']) }}</strong></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endif

        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-end mb-3 gap-2">
                    <select id="earningStatusFilter" class="form-select" style="max-width: 220px;">
                        <option value="pending" @selected($status === 'pending')>{{ __('Held') }}</option>
                        <option value="released" @selected($status === 'released')>{{ __('Released') }}</option>
                        <option value="cancelled" @selected($status === 'cancelled')>{{ __('Cancelled') }}</option>
                        <option value="all" @selected($status === 'all')>{{ __('All') }}</option>
                    </select>
                    <div class="input-group" style="max-width: 320px;">
                        <input type="text" id="earningSearchInput" class="form-control"
                            placeholder="{{ __('Search by driver name or phone...') }}">
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-borderless table-striped" id="table_list">
                        <thead class="table-light">
                            <tr>
                                <th>{{ __('Driver') }}</th>
                                <th>{{ __('Order') }}</th>
                                <th>{{ __('Journey') }}</th>
                                <th>{{ __('Amount') }}</th>
                                <th>{{ __('How it was worked out') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th>{{ __('When') }}</th>
                            </tr>
                        </thead>
                        <tbody id="earning-table-body">
                            @include('admin.earning.partials._earning_table_body', ['earnings' => $earnings])
                        </tbody>
                    </table>
                </div>

                <div id="pagination-wrapper">
                    {{ $earnings->withQueryString()->links() }}
                </div>
            </div>
        </div>
    </section>
@endsection

@push('scripts')
    <script>
        $(document).ready(function () {
            setupAjaxSearch({
                inputSelector: '#earningSearchInput',
                tableBodySelector: '#earning-table-body',
                paginationWrapperSelector: '#pagination-wrapper',
                url: "{{ route('admin.earning.search') }}",
                colspan: 7
            });

            $('#earningStatusFilter').on('change', function () {
                window.location = "{{ route('admin.earning.index') }}?status=" + $(this).val();
            });
        });
    </script>
@endpush
