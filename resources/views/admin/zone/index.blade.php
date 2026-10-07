@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Zones') }}</h5>
        @if (canDo('zone.create'))
            <a href="{{ route('admin.zone.create') }}" class="btn-add">
                <i class="fa fa-plus"></i> {{ __('Add Zone') }}
            </a>
        @endif
    </div>

    <section class="section">
        <div class="row">
            <div class="col-md-12">
                {{-- Every drawn zone at once — where the gaps are, and which
                     zones still have to be drawn (the ones not on it). --}}
                @if (! empty($drawnZones))
                    <div class="card mb-3">
                        <div class="card-header"><h6 class="mb-0">{{ __('The zones on the map') }}</h6></div>
                        <div class="card-body">
                            <input type="hidden" id="zones-overview" value="">
                            <x-zone-drawer input="zones-overview" :others="$drawnZones" :readonly="true" height="380px" />
                        </div>
                    </div>
                @endif

                <div class="card">
                    <div class="card-body">
                        {{-- The switch is how the owner chooses where orders are
                             taken (Address::isCovered()), so say it where it is. --}}
                        <p class="text-muted mb-3">
                            {{ __('Orders are taken only in zones that are switched on. A customer in a zone switched off, or outside every zone, is told the service is not in their area yet and appears under «Out of coverage».') }}
                        </p>
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                            <x-spreadsheet-actions sheet="zone" search="#zoneSearchInput" />
                            <div class="input-group" style="max-width: 350px;">
                                <input type="text" id="zoneSearchInput" class="form-control"
                                    placeholder="{{ __('Search Zone...') }}">
                            </div>
                        </div>

                        <div class="table-responsive">
                        @php
                            // Shared by the label strip and every row, so the labels
                            // sit exactly over the fields they name.
                            $stackCols = 'minmax(9rem,1.4fr) minmax(8rem,1fr) minmax(5rem,.6fr) minmax(7rem,auto) minmax(6rem,auto)';
                        @endphp

                        <div class="stack-head" style="--stack-cols: {{ $stackCols }}">
                            <span>{{ __('Name') }}</span>
                            <span>{{ __('City') }}</span>
                            <span>{{ __('Sort Order') }}</span>
                            <span>{{ __('Status') }}</span>
                            <span class="text-end">{{ __('Action') }}</span>
                        </div>

                        <div class="data-stack" id="zone-table-body" style="--stack-cols: {{ $stackCols }}">
                            @include('admin.zone.partials._zone_table_body', ['zones' => $zones])
                        </div>

</div>

                        <div id="pagination-wrapper">
                            {{ $zones->withQueryString()->links() }}
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
                inputSelector: '#zoneSearchInput',
                tableBodySelector: '#zone-table-body',
                paginationWrapperSelector: '#pagination-wrapper',
                url: "{{ route('admin.zone.search') }}",
                // Card rows, not a table: the helper's default
                // <tr><td colspan> failure message would be stray
                // markup here.
                errorHtml: '<div class="stack-empty text-danger">Error during search</div>'
            });
        });
    </script>
@endpush
