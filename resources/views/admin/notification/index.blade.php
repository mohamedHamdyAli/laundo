@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Notification Log') }}</h5>
    </div>

    <section class="section">
        <div class="row mb-3">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body">
                        <h6 class="text-muted mb-1">{{ __('Sent') }}</h6>
                        <h3 class="mb-0 text-success">{{ $counts['sent'] }}</h3>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body">
                        <h6 class="text-muted mb-1">{{ __('Failed') }}</h6>
                        <h3 class="mb-0 {{ $counts['failed'] > 0 ? 'text-danger' : '' }}">
                            {{ $counts['failed'] }}
                        </h3>
                        {{-- A dead push token fails on every send forever; counting
                             is the only way to notice. --}}
                        <small class="text-muted">{{ __('A repeating failure usually means a dead device token') }}</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body">
                        <h6 class="text-muted mb-1">{{ __('Skipped') }}</h6>
                        <h3 class="mb-0">{{ $counts['skipped'] }}</h3>
                        <small class="text-muted">{{ __('Muted, or no registered device') }}</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <p class="text-muted small">
                            {{ __('Every message the system tried to send, including the ones it deliberately did not. Transactional messages ignore a muted channel — an order waiting on a customer who was never told simply stops.') }}
                        </p>

                        <div class="d-flex justify-content-end mb-3 gap-2">
                            <select id="notificationEventFilter" class="form-select" style="max-width: 220px;">
                                <option value="">{{ __('All events') }}</option>
                                @foreach ($events as $option)
                                    <option value="{{ $option->value }}" @selected($event === $option->value)>
                                        {{ __($option->label()) }}
                                    </option>
                                @endforeach
                            </select>
                            <select id="notificationStatusFilter" class="form-select" style="max-width: 180px;">
                                <option value="">{{ __('All statuses') }}</option>
                                <option value="sent" @selected($status === 'sent')>{{ __('Sent') }}</option>
                                <option value="failed" @selected($status === 'failed')>{{ __('Failed') }}</option>
                                <option value="skipped" @selected($status === 'skipped')>{{ __('Skipped') }}</option>
                            </select>
                            <div class="input-group" style="max-width: 300px;">
                                <input type="text" id="notificationSearchInput" class="form-control"
                                    placeholder="{{ __('Search by recipient or text...') }}">
                            </div>
                        </div>

                        <div class="table-responsive">
                        @php
                            // Shared by the label strip and every row, so the labels
                            // sit exactly over the fields they name.
                            $stackCols = 'minmax(8rem,1.1fr) minmax(9rem,1.1fr) minmax(12rem,1.8fr) minmax(8rem,auto)';
                        @endphp

                        <div class="stack-head" style="--stack-cols: {{ $stackCols }}">
                            <span>{{ __('Recipient') }}</span>
                            <span>{{ __('Event') }}</span>
                            <span>{{ __('Message') }}</span>
                            <span>{{ __('Status') }}</span>
                        </div>

                        <div class="data-stack" id="notification-table-body" style="--stack-cols: {{ $stackCols }}">
                            @include('admin.notification.partials._notification_table_body', ['logs' => $logs])
                        </div>

                        </div>

                        <div id="pagination-wrapper">
                            {{ $logs->withQueryString()->links() }}
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
                inputSelector: '#notificationSearchInput',
                tableBodySelector: '#notification-table-body',
                paginationWrapperSelector: '#pagination-wrapper',
                url: "{{ route('admin.notification.search') }}",
                // Card rows, not a table: the helper's default
                // <tr><td colspan> failure message would be stray
                // markup here.
                errorHtml: '<div class="stack-empty text-danger">Error during search</div>'
            });

            // Both filters hit the same endpoint and compose with the term, so a
            // narrowed view is not silently thrown away by typing.
            $('#notificationEventFilter, #notificationStatusFilter').on('change', function () {
                $.get("{{ route('admin.notification.search') }}", {
                    query: $('#notificationSearchInput').val(),
                    event: $('#notificationEventFilter').val(),
                    status: $('#notificationStatusFilter').val()
                }, function (response) {
                    $('#notification-table-body').html(response.table);
                    $('#pagination-wrapper').html(response.pagination);
                });
            });
        });
    </script>
@endpush
