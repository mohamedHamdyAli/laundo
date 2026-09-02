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
                        {{-- `text-attention`, not `text-info`: the sibling money screens
                             (payments, driver earnings) already use it for «a nonzero count
                             that needs looking at», and Bootstrap's info cyan measures
                             1.96:1 on white — unreadable for the one number on the card. --}}
                        <h3 class="mb-0 {{ $counts['pending'] > 0 ? 'text-attention' : '' }}">
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
                        @php
                            // Shared by the label strip and every row, so the labels
                            // sit exactly over the fields they name.
                            $stackCols = 'minmax(7rem,.9fr) minmax(8rem,1fr) minmax(9rem,1.2fr) minmax(7rem,auto) minmax(6rem,auto) minmax(9rem,auto)';
                        @endphp

                        <div class="stack-head" style="--stack-cols: {{ $stackCols }}">
                            <span>{{ __('Order') }}</span>
                            <span>{{ __('Customer') }}</span>
                            <span>{{ __('Reason') }}</span>
                            <span>{{ __('Status') }}</span>
                            <span class="text-end">{{ __('Amount') }}</span>
                            <span class="text-end">{{ __('Action') }}</span>
                        </div>

                        <div class="data-stack" id="refund-table-body" style="--stack-cols: {{ $stackCols }}">
                            @include('admin.refund.partials._refund_table_body', ['refunds' => $refunds])
                        </div>

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
                // Card rows, not a table: the helper's default
                // <tr><td colspan> failure message would be stray
                // markup here.
                errorHtml: '<div class="stack-empty text-danger">Error during search</div>'
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
