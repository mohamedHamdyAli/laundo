@extends('layouts.main')
@section('content')
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h5 class="card-title mb-0">{{ __('Journey Steps') }}</h5>
            {{-- The list is short and its order is the whole point, so say what
                 it drives rather than leaving «Journey Steps» to be guessed at. --}}
            <span class="row-sub">{{ __('The numbered «how it works» cards on the app home screen.') }}</span>
        </div>

        @if (canDo('journey_step.create'))
            <a href="{{ route('admin.journey_step.create') }}" class="btn-add">
                <i class="fa fa-plus"></i>{{ __('Add Step') }}
            </a>
        @endif
    </div>

    <section class="section">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <div class="list-toolbar">
                            <input type="text" id="journeyStepSearchInput" name="journeyStepSearch"
                                value="{{ request('journeyStepSearch') }}" class="form-control"
                                placeholder="{{ __('Search Step...') }}">
                        </div>

                        <div id="search-info"></div>

                        @php
                            // Shared by the label strip and every row, so the labels
                            // sit exactly over the fields they name.
                            $stackCols = 'minmax(3.4rem,3.5rem) minmax(3.8rem,4rem) minmax(11rem,1.6fr) minmax(7rem,auto) minmax(6rem,auto)';
                        @endphp

                        <div class="stack-head" style="--stack-cols: {{ $stackCols }}">
                            <span>{{ __('Step') }}</span>
                            <span>{{ __('Icon') }}</span>
                            <span>{{ __('Title') }}</span>
                            <span>{{ __('Status') }}</span>
                            <span class="text-end">{{ __('Action') }}</span>
                        </div>

                        <div class="data-stack" id="journey_step-table-body" style="--stack-cols: {{ $stackCols }}">
                            @include('admin.journey_step.partials._journey_step_table_body', ['journeySteps' => $journeySteps])
                        </div>

                        <div id="pagination-wrapper">
                            {{ $journeySteps->withQueryString()->links() }}
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
                inputSelector: '#journeyStepSearchInput',
                tableBodySelector: '#journey_step-table-body',
                paginationWrapperSelector: '#pagination-wrapper',
                url: "{{ route('admin.journey_step.search') }}",
                // Card rows, not a table: the helper's default
                // <tr><td colspan> failure message would be stray markup here.
                errorHtml: '<div class="stack-empty text-danger">{{ __('Error during search') }}</div>'
            });
        });
    </script>
@endpush
