@extends('layouts.main')

@section('content')
    @php
        // Counted here rather than passed in, so the badge cannot go stale on
        // the AJAX search path — which re-renders the body and not the header.
        $pendingApplications = canDo('laundry.update')
            ? \App\Modules\Laundry\Models\Laundry::withoutGlobalScopes()->pending()->count()
            : 0;
    @endphp

    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Laundries') }}</h5>
        <div class="d-flex align-items-center gap-2">
            {{-- Only when there is something to look at. A permanent link to an
                 empty screen is one an operator learns to ignore, and this one
                 has to be noticed on the day it is not empty. --}}
            @if ($pendingApplications > 0)
                <a href="{{ route('admin.laundry.pending') }}" class="btn btn-warning btn-sm">
                    <i class="bi bi-hourglass-split"></i>
                    {{ __('Applications') }}
                    <span class="badge bg-dark ms-1">{{ $pendingApplications }}</span>
                </a>
            @endif
            @if (canDo('laundry.create'))
                <a href="{{ route('admin.laundry.create') }}" class="btn-add">
                    <i class="fa fa-plus"></i> {{ __('Add Laundry') }}
                </a>
            @endif
        </div>
    </div>

    <section class="section">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-end mb-3">
                            <div class="input-group" style="max-width: 350px;">
                                <input type="text" id="laundrySearchInput" name="laundrySearch"
                                    value="{{ request('laundrySearch') }}" class="form-control"
                                    placeholder="{{ __('Search Laundry...') }}">
                            </div>
                        </div>

                        <div class="table-responsive">
                        @php
                            // Shared by the label strip and every row, so the labels
                            // sit exactly over the fields they name.
                            $stackCols = 'minmax(3.8rem,4rem) minmax(9rem,1.3fr) minmax(8rem,1fr) minmax(8rem,1fr) minmax(7rem,auto) minmax(6rem,auto)';
                        @endphp

                        <div class="stack-head" style="--stack-cols: {{ $stackCols }}">
                            <span>{{ __('Logo') }}</span>
                            <span>{{ __('Name') }}</span>
                            <span>{{ __('Phone') }}</span>
                            <span>{{ __('City') }}</span>
                            <span>{{ __('Status') }}</span>
                            <span class="text-end">{{ __('Action') }}</span>
                        </div>

                        <div class="data-stack" id="laundry-table-body" style="--stack-cols: {{ $stackCols }}">
                            @include('admin.laundry.partials._laundry_table_body', ['laundries' => $laundries])
                        </div>

                        </div>

                        <div id="pagination-wrapper">
                            {{ $laundries->withQueryString()->links() }}
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
                inputSelector: '#laundrySearchInput',
                tableBodySelector: '#laundry-table-body',
                paginationWrapperSelector: '#pagination-wrapper',
                url: "{{ route('admin.laundry.search') }}",
                // Card rows, not a table: the helper's default
                // <tr><td colspan> failure message would be stray
                // markup here.
                errorHtml: '<div class="stack-empty text-danger">Error during search</div>'
            });
        });
    </script>
@endpush
