@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Commissions') }}</h5>
        @if (canDo('commission_rule.create'))
            <a href="{{ route('admin.commission_rule.create') }}" class="btn-add">
                <i class="fa fa-plus"></i> {{ __('Add Commission') }}
            </a>
        @endif
    </div>

    <section class="section">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <p class="text-muted small">
                            {{ __('Each rule is the share of the washing a laundry receives; the rest stays with the platform. A laundry is on one share at a time.') }}
                            <strong>{{ __('A laundry on no share follows the general share in Settings.') }}</strong>
                        </p>

                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                            {{-- Excel: the export holds what the search shows. --}}
                            <x-spreadsheet-actions sheet="commission_rule" search="#commissionSearchInput" />
                            <div class="input-group" style="max-width: 350px;">
                                <input type="text" id="commissionSearchInput" class="form-control"
                                    placeholder="{{ __('Search commissions...') }}">
                            </div>
                        </div>

                        <div class="table-responsive">
                            @php
                                // Shared by the label strip and every row, so the labels
                                // sit exactly over the fields they name.
                                $stackCols = 'minmax(10rem,1.4fr) minmax(8rem,1fr) minmax(6rem,auto) minmax(6rem,auto) minmax(7rem,auto)';
                            @endphp

                            <div class="stack-head" style="--stack-cols: {{ $stackCols }}">
                                <span>{{ __('Name') }}</span>
                                <span>{{ __('Laundry share') }}</span>
                                <span>{{ __('Laundries') }}</span>
                                <span>{{ __('Status') }}</span>
                                <span class="text-end">{{ __('Action') }}</span>
                            </div>

                            <div class="data-stack" id="commission-table-body" style="--stack-cols: {{ $stackCols }}">
                                @include('admin.commission_rule.partials._commission_rule_table_body', ['rules' => $rules])
                            </div>
                        </div>

                        <div id="pagination-wrapper">
                            {{ $rules->withQueryString()->links() }}
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
                inputSelector: '#commissionSearchInput',
                tableBodySelector: '#commission-table-body',
                paginationWrapperSelector: '#pagination-wrapper',
                url: "{{ route('admin.commission_rule.search') }}",
                // Card rows, not a table: the helper's default
                // <tr><td colspan> failure message would be stray markup here.
                errorHtml: '<div class="stack-empty text-danger">Error during search</div>'
            });
        });
    </script>
@endpush
