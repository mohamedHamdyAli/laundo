@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Laundry Staff') }}</h5>
        @if (canDo('laundry_staff.create'))
            <a href="{{ route('admin.laundry_staff.create') }}" class="btn-add">
                <i class="fa fa-plus"></i> {{ __('Add Staff') }}
            </a>
        @endif
    </div>

    <section class="section">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-end mb-3">
                            <div class="input-group" style="max-width: 350px;">
                                <input type="text" id="staffSearchInput" name="staffSearch"
                                    value="{{ request('staffSearch') }}" class="form-control"
                                    placeholder="{{ __('Search Staff...') }}">
                            </div>
                        </div>

                        <div class="table-responsive">
                        @php
                            // Shared by the label strip and every row, so the labels
                            // sit exactly over the fields they name.
                            $stackCols = 'minmax(3.8rem,4rem) minmax(9rem,1.3fr) minmax(8rem,1fr) minmax(7rem,auto) minmax(7rem,auto) minmax(6rem,auto)';
                        @endphp

                        <div class="stack-head" style="--stack-cols: {{ $stackCols }}">
                            <span>{{ __('Image') }}</span>
                            <span>{{ __('Name') }}</span>
                            <span>{{ __('Laundry') }}</span>
                            <span>{{ __('Role') }}</span>
                            <span>{{ __('Status') }}</span>
                            <span class="text-end">{{ __('Action') }}</span>
                        </div>

                        <div class="data-stack" id="laundry_staff-table-body" style="--stack-cols: {{ $stackCols }}">
                            @include('admin.laundry_staff.partials._laundry_staff_table_body', ['staff' => $staff])
                        </div>

                        </div>

                        <div id="pagination-wrapper">
                            {{ $staff->withQueryString()->links() }}
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
                inputSelector: '#staffSearchInput',
                tableBodySelector: '#staff-table-body',
                paginationWrapperSelector: '#pagination-wrapper',
                url: "{{ route('admin.laundry_staff.search') }}",
                // Card rows, not a table: the helper's default
                // <tr><td colspan> failure message would be stray
                // markup here.
                errorHtml: '<div class="stack-empty text-danger">Error during search</div>'
            });
        });
    </script>
@endpush
