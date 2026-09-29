@extends('layouts.main')

{{-- «سجل النشاط» — who changed what. Read only: nothing here edits or removes a row. --}}

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Activity Log') }}</h5>
        <span class="text-muted small">{{ __('Who changed what, when, and from where. Kept for six months.') }}</span>
    </div>

    <section class="section">
        <div class="card">
            <div class="card-body">
                {{-- Each filter in a cell of its own: every panel select becomes a
                     100%-wide select2, and laid straight into a flex row each
                     would take a whole line. --}}
                <div class="activity-filters mb-3">
                    <div>
                        <select id="activitySourceFilter" class="form-select" aria-label="{{ __('Source') }}">
                            <option value="">{{ __('From anywhere') }}</option>
                            <option value="dashboard" @selected($filters['source'] === 'dashboard')>{{ __('Control panel') }}</option>
                            <option value="api" @selected($filters['source'] === 'api')>{{ __('The apps') }}</option>
                            <option value="site" @selected($filters['source'] === 'site')>{{ __('Website') }}</option>
                            <option value="system" @selected($filters['source'] === 'system')>{{ __('Automatically, by the system') }}</option>
                        </select>
                    </div>
                    <div>
                        <select id="activityEventFilter" class="form-select" aria-label="{{ __('Action') }}">
                            <option value="">{{ __('All actions') }}</option>
                            <option value="created" @selected($filters['event'] === 'created')>{{ __('Added') }}</option>
                            <option value="updated" @selected($filters['event'] === 'updated')>{{ __('Changed') }}</option>
                            <option value="deleted" @selected($filters['event'] === 'deleted')>{{ __('Removed') }}</option>
                            <option value="login" @selected($filters['event'] === 'login')>{{ __('Signed in') }}</option>
                            <option value="logout" @selected($filters['event'] === 'logout')>{{ __('Signed out') }}</option>
                        </select>
                    </div>
                    <div>
                        <input type="date" id="activityFrom" class="form-control" value="{{ $filters['from'] }}" aria-label="{{ __('From') }}">
                    </div>
                    <div>
                        <input type="date" id="activityTo" class="form-control" value="{{ $filters['to'] }}" aria-label="{{ __('To') }}">
                    </div>
                    <div class="activity-search">
                        <input type="text" id="activitySearchInput" class="form-control" autocomplete="off"
                            value="{{ $filters['query'] }}" placeholder="{{ __('Search by a person or a record name...') }}">
                    </div>
                </div>

                <div class="mb-3">
                    <x-spreadsheet-actions sheet="activity_log" search="#activitySearchInput"
                        :filters="['source' => '#activitySourceFilter', 'event' => '#activityEventFilter', 'from' => '#activityFrom', 'to' => '#activityTo']" />
                </div>

                @php
                    $stackCols = 'minmax(6.5rem,auto) minmax(14rem,2fr) minmax(9rem,1fr) minmax(9rem,1fr) auto';
                @endphp

                <div class="stack-head" style="--stack-cols: {{ $stackCols }}">
                    <span>{{ __('When') }}</span>
                    <span>{{ __('What happened') }}</span>
                    <span>{{ __('Done by') }}</span>
                    <span>{{ __('Where from') }}</span>
                    <span class="text-end">{{ __('Details') }}</span>
                </div>

                <div class="data-stack" id="activity-table-body" style="--stack-cols: {{ $stackCols }}">
                    @include('admin.activity_log.partials._activity_log_table_body', ['rows' => $rows])
                </div>

                <div class="mt-3" id="activity-pagination">
                    {{ $logs->withQueryString()->links() }}
                </div>
            </div>
        </div>
    </section>
@endsection

@push('styles')
    <style>
        .activity-filters { display: grid; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); gap: .75rem; }
        .activity-filters .activity-search { grid-column: span 2; }
        @media (max-width: 575.98px) { .activity-filters .activity-search { grid-column: auto; } }
        .activity-details { grid-column: 1 / -1; }
        .activity-toggle i { transition: transform .15s ease; display: inline-block; }
        .activity-toggle[aria-expanded="true"] i { transform: rotate(180deg); }
    </style>
@endpush

@push('scripts')
    <script>
        $(function () {
            setupAjaxSearch({
                inputSelector: '#activitySearchInput',
                tableBodySelector: '#activity-table-body',
                paginationWrapperSelector: '#activity-pagination',
                url: @json(route('admin.activity_log.search')),
                errorHtml: '<div class="stack-empty text-danger">{{ __('Error during search') }}</div>',
                // Read at request time, so the filters and the term compose.
                extraParams: () => ({
                    source: $('#activitySourceFilter').val(),
                    event: $('#activityEventFilter').val(),
                    from: $('#activityFrom').val(),
                    to: $('#activityTo').val(),
                }),
            });

            $('#activitySourceFilter, #activityEventFilter, #activityFrom, #activityTo').on('change', function () {
                $.get(@json(route('admin.activity_log.search')), {
                    query: $('#activitySearchInput').val(),
                    source: $('#activitySourceFilter').val(),
                    event: $('#activityEventFilter').val(),
                    from: $('#activityFrom').val(),
                    to: $('#activityTo').val(),
                }, function (response) {
                    $('#activity-table-body').html(response.table);
                    $('#activity-pagination').html(response.pagination);
                });
            });
        });
    </script>
@endpush
