@extends('layouts.main')

{{-- «طلبات خدمات المغاسل» — a laundry asking to open or close a service.

     No «Add» and no edit: a row arrives from a laundry's own services screen,
     and the only two things anybody does to it are approve and refuse. A
     refusal takes a note, and the note reaches the laundry. --}}

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">
            {{ __('Service Requests') }}
            @if ($pendingCount > 0)
                <span class="badge bg-warning text-dark ms-2">{{ __(':count waiting', ['count' => $pendingCount]) }}</span>
            @endif
        </h5>
    </div>

    <section class="section">
        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                    {{-- Excel: the export holds what the search and the status
                         filter show. --}}
                    <x-spreadsheet-actions sheet="laundry_service_request" search="#serviceRequestSearchInput"
                        :filters="['status' => '#serviceRequestStatusFilter']" />
                    <div class="d-flex justify-content-end align-items-center gap-2 flex-wrap">
                        <div style="min-width: 200px;">
                            <select id="serviceRequestStatusFilter" class="form-select">
                                <option value="pending" @selected($status === 'pending')>{{ __('Awaiting review') }}</option>
                                <option value="approved" @selected($status === 'approved')>{{ __('Approved') }}</option>
                                <option value="rejected" @selected($status === 'rejected')>{{ __('Rejected') }}</option>
                                <option value="all" @selected($status === 'all')>{{ __('All') }}</option>
                            </select>
                        </div>
                        <div style="min-width: 280px;">
                            <input type="text" id="serviceRequestSearchInput" class="form-control"
                                placeholder="{{ __('Search by laundry or service...') }}">
                        </div>
                    </div>
                </div>

                @php
                    $stackCols = 'minmax(10rem,1.3fr) minmax(10rem,1.3fr) minmax(8rem,auto) minmax(9rem,auto) minmax(12rem,auto)';
                @endphp

                <div class="stack-head" style="--stack-cols: {{ $stackCols }}">
                    <span>{{ __('Laundry') }}</span>
                    <span>{{ __('Asks to') }}</span>
                    <span>{{ __('Waiting since') }}</span>
                    <span>{{ __('Status') }}</span>
                    <span class="text-end">{{ __('Action') }}</span>
                </div>

                <div class="data-stack" id="service-request-table-body" style="--stack-cols: {{ $stackCols }}">
                    @include('admin.laundry_service_request.partials._laundry_service_request_table_body', ['requests' => $requests])
                </div>

                <div class="mt-3" id="service-request-pagination">
                    {{ $requests->withQueryString()->links() }}
                </div>
            </div>
        </div>
    </section>

    @if (canDo('laundry_service_request.update'))
        {{-- One refusal dialog for the whole list, filled from the row's button.
             A plain form, not `needs-validation`: the note is the only thing
             typed, and the server refuses an empty one with a message. --}}
        <div class="modal fade" id="serviceRequestRejectModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form method="POST" id="serviceRequestRejectForm">
                    @csrf
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">{{ __('Reject the request') }}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                        </div>
                        <div class="modal-body">
                            <p class="text-muted small mb-2" id="serviceRequestRejectSummary"></p>
                            <label class="form-label" for="serviceRequestRejectNote">{{ __('Reason') }}</label>
                            <textarea class="form-control" id="serviceRequestRejectNote" name="note" rows="3"
                                maxlength="1000" required placeholder="{{ __('The laundry will read this.') }}"></textarea>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                            <button type="submit" class="btn btn-danger">{{ __('Reject') }}</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    @endif
@endsection

@push('scripts')
    <script>
        $(function () {
            setupAjaxSearch({
                inputSelector: '#serviceRequestSearchInput',
                tableBodySelector: '#service-request-table-body',
                paginationWrapperSelector: '#service-request-pagination',
                url: @json(route('admin.laundry_service_request.search')),
                errorHtml: '<div class="stack-empty text-danger">{{ __('Error during search') }}</div>',
                // Read at request time, so the filter and the term compose.
                extraParams: () => ({ status: $('#serviceRequestStatusFilter').val() }),
            });

            $('#serviceRequestStatusFilter').on('change', function () {
                $.get(@json(route('admin.laundry_service_request.search')), {
                    query: $('#serviceRequestSearchInput').val(),
                    status: $(this).val(),
                }, function (response) {
                    $('#service-request-table-body').html(response.table);
                    $('#service-request-pagination').html(response.pagination);
                });
            });

            // Delegated: the search replaces the rows wholesale, and a handler
            // bound to the buttons themselves would stop working after one
            // keystroke.
            $(document).on('click', '.js-service-request-reject', function () {
                const $btn = $(this);

                $('#serviceRequestRejectForm').attr('action', $btn.data('action'));
                $('#serviceRequestRejectSummary').text($btn.data('summary'));
                $('#serviceRequestRejectNote').val('');

                bootstrap.Modal.getOrCreateInstance(document.getElementById('serviceRequestRejectModal')).show();
            });
        });
    </script>
@endpush
