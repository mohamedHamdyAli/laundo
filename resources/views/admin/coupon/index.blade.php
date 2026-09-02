@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Discount Codes') }}</h5>
        @if (canDo('coupon.create'))
            <a href="{{ route('admin.coupon.create') }}" class="btn-add">
                <i class="fa fa-plus"></i> {{ __('Add Code') }}
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
                                <input type="text" id="couponSearchInput" class="form-control"
                                    placeholder="{{ __('Search by code...') }}">
                            </div>
                        </div>

                        <div class="table-responsive">
                        @php
                            // Shared by the label strip and every row, so the labels
                            // sit exactly over the fields they name.
                            $stackCols = 'minmax(9rem,1.3fr) minmax(8rem,1fr) minmax(7rem,.9fr) minmax(8rem,1.1fr) minmax(7rem,auto) minmax(6rem,auto)';
                        @endphp

                        <div class="stack-head" style="--stack-cols: {{ $stackCols }}">
                            <span>{{ __('Code') }}</span>
                            <span>{{ __('Discount') }}</span>
                            <span>{{ __('Claimed') }}</span>
                            <span>{{ __('Window') }}</span>
                            <span>{{ __('Status') }}</span>
                            <span class="text-end">{{ __('Action') }}</span>
                        </div>

                        <div class="data-stack" id="coupon-table-body" style="--stack-cols: {{ $stackCols }}">
                            @include('admin.coupon.partials._coupon_table_body', ['coupons' => $coupons])
                        </div>

                        </div>

                        <div id="pagination-wrapper">
                            {{ $coupons->withQueryString()->links() }}
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
                inputSelector: '#couponSearchInput',
                tableBodySelector: '#coupon-table-body',
                paginationWrapperSelector: '#pagination-wrapper',
                url: "{{ route('admin.coupon.search') }}",
                // Card rows, not a table: the helper's default
                // <tr><td colspan> failure message would be stray
                // markup here.
                errorHtml: '<div class="stack-empty text-danger">Error during search</div>'
            });
        });
    </script>
@endpush
