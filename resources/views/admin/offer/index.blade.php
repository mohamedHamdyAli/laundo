@extends('layouts.main')
@section('content')
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="card-title mb-0">{{ __('Offers') }}</h5>

        @if (canDo('offer.create'))
            <a href="{{ route('admin.offer.create') }}" class="btn-add">
                <i class="fa fa-plus"></i>{{ __('Add Offer') }}
            </a>
        @endif
    </div>

    <section class="section">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <div class="list-toolbar">
                            <input type="text" id="offerSearchInput" name="offerSearch"
                                value="{{ request('offerSearch') }}" class="form-control"
                                placeholder="{{ __('Search Offer...') }}">
                        </div>

                        <div id="search-info"></div>

                        @php
                            // Shared by the label strip and every row, so the labels
                            // sit exactly over the fields they name.
                            $stackCols = 'minmax(3.8rem,4rem) minmax(10rem,1.5fr) minmax(6rem,auto) minmax(9rem,1fr) minmax(7rem,auto) minmax(6rem,auto)';
                        @endphp

                        <div class="stack-head" style="--stack-cols: {{ $stackCols }}">
                            <span>{{ __('Image') }}</span>
                            <span>{{ __('Title') }}</span>
                            <span>{{ __('Discount') }}</span>
                            <span>{{ __('Schedule') }}</span>
                            <span>{{ __('Status') }}</span>
                            <span class="text-end">{{ __('Action') }}</span>
                        </div>

                        <div class="data-stack" id="offer-table-body" style="--stack-cols: {{ $stackCols }}">
                            @include('admin.offer.partials._offer_table_body', ['offers' => $offers])
                        </div>

                        <div id="pagination-wrapper">
                            {{ $offers->withQueryString()->links() }}
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
                inputSelector: '#offerSearchInput',
                tableBodySelector: '#offer-table-body',
                paginationWrapperSelector: '#pagination-wrapper',
                url: "{{ route('admin.offer.search') }}",
                // Card rows, not a table: the helper's default
                // <tr><td colspan> failure message would be stray markup here.
                errorHtml: '<div class="stack-empty text-danger">{{ __('Error during search') }}</div>'
            });
        });
    </script>
@endpush
