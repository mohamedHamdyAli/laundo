@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Payments') }}</h5>
    </div>

    <section class="section">
        <div class="row mb-3">
            <div class="col-md-3">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1">{{ __('Taken today') }}</h6>
                    <h3 class="mb-0">{{ moneyFormat($summary['captured_today']) }}</h3>
                    <small class="text-muted">
                        {{ __('This month') }} {{ moneyFormat($summary['captured_month']) }}
                    </small>
                </div></div>
            </div>
            <div class="col-md-3">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1">{{ __('Authorised, not captured') }}</h6>
                    <h3 class="mb-0 {{ $summary['authorised_uncaptured'] > 0 ? 'text-danger' : '' }}">
                        {{ $summary['authorised_uncaptured'] }}
                    </h3>
                    {{-- The most expensive state to leave unnoticed: the customer
                         believes they have paid, we have not taken the money, and
                         the authorisation expires. --}}
                    <small class="text-muted">{{ __('The hold expires if left') }}</small>
                </div></div>
            </div>
            <div class="col-md-3">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1">{{ __('Stuck pending') }}</h6>
                    <h3 class="mb-0 {{ $summary['stuck'] > 0 ? 'text-attention' : '' }}">
                        {{ $summary['stuck'] }}
                    </h3>
                    <small class="text-muted">{{ __('An abandoned redirect, or a webhook that never came') }}</small>
                </div></div>
            </div>
            <div class="col-md-3">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1">{{ __('Failed today') }}</h6>
                    <h3 class="mb-0 {{ $summary['failed_today'] > 0 ? 'text-attention' : '' }}">
                        {{ $summary['failed_today'] }}
                    </h3>
                    <small class="text-muted">{{ __('Declined or errored') }}</small>
                </div></div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-end mb-3 gap-2">
                    <select id="paymentStatusFilter" class="form-select" style="max-width: 240px;">
                        <option value="all" @selected($status === 'all')>{{ __('All') }}</option>
                        @foreach ($statuses as $case)
                            <option value="{{ $case->value }}" @selected($status === $case->value)>
                                {{ __(ucfirst(str_replace('_', ' ', $case->value))) }}
                            </option>
                        @endforeach
                    </select>
                    <div class="input-group" style="max-width: 340px;">
                        <input type="text" id="paymentSearchInput" class="form-control"
                            placeholder="{{ __('Search by order, customer or reference...') }}">
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-borderless table-striped" id="table_list">
                        <thead class="table-light">
                            <tr>
                                <th>{{ __('Order') }}</th>
                                <th>{{ __('Customer') }}</th>
                                <th>{{ __('Amount') }}</th>
                                <th>{{ __('Method') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th>{{ __('When') }}</th>
                                <th>{{ __('Provider reference') }}</th>
                            </tr>
                        </thead>
                        <tbody id="payment-table-body">
                            @include('admin.payment.partials._payment_table_body', ['payments' => $payments])
                        </tbody>
                    </table>
                </div>

                <div id="pagination-wrapper">
                    {{ $payments->withQueryString()->links() }}
                </div>
            </div>
        </div>
    </section>
@endsection

@push('scripts')
    <script>
        $(document).ready(function () {
            setupAjaxSearch({
                inputSelector: '#paymentSearchInput',
                tableBodySelector: '#payment-table-body',
                paginationWrapperSelector: '#pagination-wrapper',
                url: "{{ route('admin.payment.search') }}",
                colspan: 7
            });

            $('#paymentStatusFilter').on('change', function () {
                window.location = "{{ route('admin.payment.index') }}?status=" + $(this).val();
            });
        });
    </script>
@endpush
