@extends('layouts.main')

{{-- Driver leads from «انضم لنا» on the public page.

     There is no «Add» button, deliberately: a row here is somebody who filled
     in the form, and one an operator typed would be a lead with nobody behind
     it. A driver is created from the Drivers screen once the call has gone
     well. --}}

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">
            {{ __('Driver Applications') }}
            @if ($waitingCount > 0)
                <span class="badge bg-warning text-dark ms-2">
                    {{ __(':count waiting', ['count' => $waitingCount]) }}
                </span>
            @endif
        </h5>
    </div>

    <section class="section">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-end mb-3">
                            <div class="input-group" style="max-width: 350px;">
                                <input type="text" id="applicationSearchInput" name="applicationSearch"
                                    value="{{ request('applicationSearch') }}" class="form-control"
                                    placeholder="{{ __('Search by name, number or note...') }}">
                            </div>
                        </div>

                        <div id="search-info"></div>

                        @php
                            // Shared by the label strip and every row, so the
                            // labels sit exactly over the fields they name.
                            $stackCols = 'minmax(8rem,1.2fr) minmax(8rem,auto) minmax(10rem,1.6fr) minmax(7rem,auto) minmax(7rem,auto) minmax(11rem,auto)';
                        @endphp

                        <div class="stack-head" style="--stack-cols: {{ $stackCols }}">
                            <span>{{ __('Name') }}</span>
                            <span>{{ __('Phone') }}</span>
                            <span>{{ __('Note') }}</span>
                            <span>{{ __('Applied') }}</span>
                            <span>{{ __('Status') }}</span>
                            <span class="text-end">{{ __('Action') }}</span>
                        </div>

                        <div class="data-stack" id="application-table-body" style="--stack-cols: {{ $stackCols }}">
                            @include('admin.driver_application.partials._driver_application_table_body', [
                                'applications' => $applications,
                            ])
                        </div>

                        <div id="pagination-wrapper">
                            {{ $applications->withQueryString()->links() }}
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
                inputSelector: '#applicationSearchInput',
                tableBodySelector: '#application-table-body',
                paginationWrapperSelector: '#pagination-wrapper',
                url: "{{ route('admin.driver_application.search') }}",
                // Card rows, not a table: the helper's default
                // <tr><td colspan> failure message would be stray markup here.
                errorHtml: '<div class="stack-empty text-danger">{{ __('Error during search') }}</div>'
            });
        });
    </script>
@endpush
