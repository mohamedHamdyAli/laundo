@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Discount Codes') }}</h5>
        @if (canDo('coupon.create'))
            <a href="{{ route('admin.coupon.create') }}" class="badge alert-info primary-background-color">
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
                            <table class="table table-borderless table-striped" id="table_list">
                                <thead class="table-light">
                                    <tr>
                                        <th>{{ __('Code') }}</th>
                                        <th>{{ __('Name') }}</th>
                                        <th>{{ __('Discount') }}</th>
                                        <th>{{ __('Used') }}</th>
                                        <th>{{ __('Window') }}</th>
                                        <th>{{ __('Status') }}</th>
                                        <th class="text-center">{{ __('Action') }}</th>
                                    </tr>
                                </thead>
                                <tbody id="coupon-table-body">
                                    @include('admin.coupon.partials._coupon_table_body', ['coupons' => $coupons])
                                </tbody>
                            </table>
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
                colspan: 7
            });
        });
    </script>
@endpush
