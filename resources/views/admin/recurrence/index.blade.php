@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Repeat Schedules') }}</h5>
    </div>

    <section class="section">
        <div class="row mb-3">
            <div class="col-md-3">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1">{{ __('Active') }}</h6>
                    <h3 class="mb-0">{{ $counts['active'] }}</h3>
                    <small class="text-muted">{{ __('Will be asked on schedule') }}</small>
                </div></div>
            </div>
            <div class="col-md-3">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1">{{ __('Paused') }}</h6>
                    <h3 class="mb-0">{{ $counts['paused'] }}</h3>
                    <small class="text-muted">{{ __('No prompts are sent') }}</small>
                </div></div>
            </div>
            <div class="col-md-3">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1">{{ __('Cancelled') }}</h6>
                    <h3 class="mb-0">{{ $counts['cancelled'] }}</h3>
                    <small class="text-muted">{{ __('Ended') }}</small>
                </div></div>
            </div>
            <div class="col-md-3">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1">{{ __('Asked and ignored') }}</h6>
                    <h3 class="mb-0 {{ $counts['unanswered'] > 0 ? 'text-attention' : '' }}">
                        {{ $counts['unanswered'] }}
                    </h3>
                    {{--
                        The figure that decides whether the daily prompt is a
                        service or a nuisance. A schedule asked eight times and
                        answered twice is a notification the customer has learned
                        to ignore, and that is invisible from a status column.
                    --}}
                    <small class="text-muted">{{ __('Prompts with no answer') }}</small>
                </div></div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-end mb-3 gap-2">
                    <select id="recurrenceStatusFilter" class="form-select" style="max-width: 200px;">
                        <option value="all" @selected($status === 'all')>{{ __('All') }}</option>
                        <option value="active" @selected($status === 'active')>{{ __('Active') }}</option>
                        <option value="paused" @selected($status === 'paused')>{{ __('Paused') }}</option>
                        <option value="cancelled" @selected($status === 'cancelled')>{{ __('Cancelled') }}</option>
                    </select>
                    <div class="input-group" style="max-width: 320px;">
                        <input type="text" id="recurrenceSearchInput" class="form-control"
                            placeholder="{{ __('Search by customer name or phone...') }}">
                    </div>
                </div>

                <div class="table-responsive">
                        @php
                            // Shared by the label strip and every row, so the labels
                            // sit exactly over the fields they name.
                            $stackCols = 'minmax(8rem,1.1fr) minmax(9rem,1.2fr) minmax(8rem,1fr) minmax(8rem,auto) minmax(8rem,auto)';
                        @endphp

                        <div class="stack-head" style="--stack-cols: {{ $stackCols }}">
                            <span>{{ __('Customer') }}</span>
                            <span>{{ __('Schedule') }}</span>
                            <span>{{ __('Prompts') }}</span>
                            <span>{{ __('Status') }}</span>
                            <span class="text-end">{{ __('Action') }}</span>
                        </div>

                        <div class="data-stack" id="recurrence-table-body" style="--stack-cols: {{ $stackCols }}">
                            @include('admin.recurrence.partials._recurrence_table_body', ['recurrences' => $recurrences])
                        </div>

                        </div>

                <div id="pagination-wrapper">
                    {{ $recurrences->withQueryString()->links() }}
                </div>
            </div>
        </div>
    </section>
@endsection

@push('scripts')
    <script>
        $(document).ready(function () {
            setupAjaxSearch({
                inputSelector: '#recurrenceSearchInput',
                tableBodySelector: '#recurrence-table-body',
                paginationWrapperSelector: '#pagination-wrapper',
                url: "{{ route('admin.recurrence.search') }}",
                // Card rows, not a table: the helper's default
                // <tr><td colspan> failure message would be stray
                // markup here.
                errorHtml: '<div class="stack-empty text-danger">Error during search</div>'
            });

            $('#recurrenceStatusFilter').on('change', function () {
                window.location = "{{ route('admin.recurrence.index') }}?status=" + $(this).val();
            });
        });
    </script>
@endpush
