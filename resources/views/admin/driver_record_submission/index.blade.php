@extends('layouts.main')

{{-- «مستندات بانتظار المراجعة» — what drivers have sent about their own records.

     There is no «Add» button and no edit: a row here arrives from the driver
     app, and the only two things anybody does to it are approve and refuse. One
     an operator typed would be an approval of something nobody sent. --}}

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">
            {{ __('Document Reviews') }}
            @if ($pendingCount > 0)
                <span class="badge bg-warning text-dark ms-2">
                    {{ __(':count waiting', ['count' => $pendingCount]) }}
                </span>
            @endif
        </h5>
    </div>

    <section class="section">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-end align-items-center gap-2 mb-3">
                            <select id="submissionStatusFilter" class="form-select" style="max-width: 200px;">
                                <option value="pending" @selected($status === 'pending')>{{ __('Awaiting review') }}</option>
                                <option value="approved" @selected($status === 'approved')>{{ __('Approved') }}</option>
                                <option value="rejected" @selected($status === 'rejected')>{{ __('Rejected') }}</option>
                                <option value="all" @selected($status === 'all')>{{ __('All') }}</option>
                            </select>

                            <div class="input-group" style="max-width: 330px;">
                                <input type="text" id="submissionSearchInput" class="form-control"
                                    placeholder="{{ __('Search by driver name or number...') }}">
                            </div>
                        </div>

                        <div id="search-info"></div>

                        @php
                            // Declared once and injected into both the label
                            // strip and the rows, so the labels sit exactly over
                            // the fields they name.
                            $stackCols = 'minmax(10rem,1.3fr) minmax(12rem,2fr) minmax(8rem,auto) minmax(9rem,auto) minmax(7rem,auto)';
                        @endphp

                        <div class="stack-head" style="--stack-cols: {{ $stackCols }}">
                            <span>{{ __('Driver') }}</span>
                            <span>{{ __('Sent') }}</span>
                            <span>{{ __('Waiting since') }}</span>
                            <span>{{ __('Status') }}</span>
                            <span class="text-end">{{ __('Action') }}</span>
                        </div>

                        <div class="data-stack" id="submission-table-body" style="--stack-cols: {{ $stackCols }}">
                            @include('admin.driver_record_submission.partials._driver_record_submission_table_body', [
                                'submissions' => $submissions,
                            ])
                        </div>

                        <div class="mt-3" id="pagination-links">
                            {{ $submissions->links() }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection

@push('scripts')
    <script>
        $(function () {
            setupAjaxSearch({
                inputSelector: '#submissionSearchInput',
                tableBodySelector: '#submission-table-body',
                paginationWrapperSelector: '#pagination-links',
                url: "{{ route('admin.driver_record_submission.search') }}",
                // A function, not an object: read at request time, so the filter
                // the operator changed after the page loaded is the one that is
                // sent. Otherwise typing a name silently widens the queue back
                // to everything.
                extraParams: () => ({
                    status: $('#submissionStatusFilter').val(),
                }),
                // Card rows, not a table: the helper's default
                // <tr><td colspan> failure message would be stray markup here.
                errorHtml: '<div class="stack-empty text-danger">{{ __('Error during search') }}</div>'
            });

            $('#submissionStatusFilter').on('change', function () {
                window.location = "{{ route('admin.driver_record_submission.index') }}?status=" + $(this).val();
            });
        });
    </script>
@endpush
