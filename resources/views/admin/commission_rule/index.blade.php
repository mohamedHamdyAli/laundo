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
                            {{ __('A laundry can carry several charges and they add together — each one appears as its own line on the settlement.') }}
                            <strong>{{ __('A laundry with nothing attached follows the general rate in Settings.') }}</strong>
                            {{ __('A laundry that pays nothing needs a charge of 0, not an empty list.') }}
                        </p>

                        <div class="d-flex justify-content-end mb-3">
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
                                <span>{{ __('Takes') }}</span>
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
