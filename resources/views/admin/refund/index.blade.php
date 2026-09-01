@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Refund Requests') }}</h5>
    </div>

    <section class="section">
        <div class="row mb-3">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-body">
                        <h6 class="text-muted mb-1">{{ __('Under review') }}</h6>
                        <h3 class="mb-0 {{ $counts['pending'] > 0 ? 'text-info' : '' }}">
                            {{ $counts['pending'] }}
                        </h3>
                        <small class="text-muted">{{ __('Waiting for a decision') }}</small>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-body">
                        <h6 class="text-muted mb-1">{{ __('Approved but unpaid') }}</h6>
                        <h3 class="mb-0 {{ $counts['unsettled'] > 0 ? 'text-danger' : '' }}">
                            {{ $counts['unsettled'] }}
                        </h3>
                        {{-- The case somebody has to chase. --}}
                        <small class="text-muted">{{ __('The payout did not complete') }}</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-end mb-3 gap-2">
                            <select id="refundStatusFilter" class="form-select" style="max-width: 220px;">
                                <option value="pending" @selected($status === 'pending')>{{ __('Under review') }}</option>
                                <option value="approved" @selected($status === 'approved')>{{ __('Approved') }}</option>
                                <option value="settled" @selected($status === 'settled')>{{ __('Refunded') }}</option>
                                <option value="rejected" @selected($status === 'rejected')>{{ __('Rejected') }}</option>
                                <option value="all" @selected($status === 'all')>{{ __('All') }}</option>
                            </select>
                            <div class="input-group" style="max-width: 320px;">
                                <input type="text" id="refundSearchInput" class="form-control"
                                    placeholder="{{ __('Search by order or customer...') }}">
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-borderless table-striped" id="table_list">
                                <thead class="table-light">
                                    <tr>
                                        <th>{{ __('Order') }}</th>
                                        <th>{{ __('Customer') }}</th>
                                        <th>{{ __('Amount') }}</th>
                                        <th>{{ __('Reason') }}</th>
                                        <th>{{ __('Status') }}</th>
                                        <th>{{ __('Requested') }}</th>
                                        <th class="text-center">{{ __('Action') }}</th>
                                    </tr>
                                </thead>
                                <tbody id="refund-table-body">
                                    @include('admin.refund.partials._refund_table_body', ['refunds' => $refunds])
                                </tbody>
                            </table>
                        </div>

                        <div id="pagination-wrapper">
                            {{ $refunds->withQueryString()->links() }}
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
                inputSelector: '#refundSearchInput',
                tableBodySelector: '#refund-table-body',
                paginationWrapperSelector: '#pagination-wrapper',
                url: "{{ route('admin.refund.search') }}",
                colspan: 7
            });

            // A filter change reloads rather than repainting: the approve modals
            // live inside the rows, and swapping the tbody would strip their
            // Bootstrap handlers.
            $('#refundStatusFilter').on('change', function () {
                window.location = "{{ route('admin.refund.index') }}?status=" + $(this).val();
            });
        });
    </script>
@endpush
