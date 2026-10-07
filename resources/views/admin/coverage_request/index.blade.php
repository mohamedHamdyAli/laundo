@extends('layouts.main')

{{-- «طلبات خارج التغطية» — customers refused because their address is in no
     zone. The app told each of them «we will contact you as soon as we reach
     your area»; this is the list that makes that true.

     No «Add» button and no edit: a row is a refused order or quote, and the
     one thing to do with it is ring the customer. --}}

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">
            {{ __('Out of coverage') }}
            @if ($readyCount > 0)
                <span class="badge bg-warning text-dark ms-2">
                    {{ __(':count covered now, nobody has called', ['count' => $readyCount]) }}
                </span>
            @endif
        </h5>
    </div>

    <section class="section">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <p class="text-muted mb-3">
                            {{ __('Customers who tried to order to or from an address outside every zone. The app told them we will contact them once we serve their area — a row marked «Covered now» is one we can call today.') }}
                        </p>

                        <div class="d-flex justify-content-end flex-wrap gap-2 mb-3">
                            <div class="input-group" style="max-width: 350px;">
                                <input type="text" id="coverageSearchInput" name="coverageSearch"
                                    value="{{ request('coverageSearch') }}" class="form-control"
                                    placeholder="{{ __('Search by name, number or address...') }}">
                            </div>
                        </div>

                        <div id="search-info"></div>

                        @php
                            // Shared by the label strip and every row, so the
                            // labels sit exactly over the fields they name.
                            $stackCols = 'minmax(8rem,1.1fr) minmax(8rem,auto) minmax(10rem,1.6fr) minmax(7rem,auto) minmax(8rem,auto) minmax(7rem,auto) minmax(9rem,auto)';
                        @endphp

                        <div class="stack-head" style="--stack-cols: {{ $stackCols }}">
                            <span>{{ __('Customer') }}</span>
                            <span>{{ __('Phone') }}</span>
                            <span>{{ __('Address') }}</span>
                            <span>{{ __('Attempts') }}</span>
                            <span>{{ __('Zone') }}</span>
                            <span>{{ __('Status') }}</span>
                            <span class="text-end">{{ __('Action') }}</span>
                        </div>

                        <div class="data-stack" id="coverage-table-body" style="--stack-cols: {{ $stackCols }}">
                            @include('admin.coverage_request.partials._coverage_request_table_body', [
                                'coverageRequests' => $coverageRequests,
                            ])
                        </div>

                        <div id="pagination-wrapper">
                            {{ $coverageRequests->withQueryString()->links() }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection

@push('scripts')
    <script>
        $(document).ready(function() {
            setupAjaxSearch({
                inputSelector: '#coverageSearchInput',
                tableBodySelector: '#coverage-table-body',
                paginationWrapperSelector: '#pagination-wrapper',
                url: "{{ route('admin.coverage_request.search') }}",
                // Card rows, not a table: the helper's default
                // <tr><td colspan> failure message would be stray markup here.
                errorHtml: '<div class="stack-empty text-danger">{{ __('Error during search') }}</div>'
            });
        });
    </script>
@endpush
