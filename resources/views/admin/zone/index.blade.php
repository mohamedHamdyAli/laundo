@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Zones') }}</h5>
        @if (canDo('zone.create'))
            <a href="{{ route('admin.zone.create') }}" class="badge alert-info primary-background-color">
                <i class="fa fa-plus"></i> {{ __('Add Zone') }}
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
                                <input type="text" id="zoneSearchInput" class="form-control"
                                    placeholder="{{ __('Search Zone...') }}">
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-borderless table-striped" id="table_list">
                                <thead class="table-light">
                                    <tr>
                                        <th>{{ __('ID') }}</th>
                                        <th>{{ __('Zone') }}</th>
                                        <th>{{ __('City') }}</th>
                                        <th>{{ __('Order') }}</th>
                                        <th>{{ __('Status') }}</th>
                                        <th class="text-center">{{ __('Action') }}</th>
                                    </tr>
                                </thead>
                                <tbody id="zone-table-body">
                                    @include('admin.zone.partials._zone_table_body', ['zones' => $zones])
                                </tbody>
                            </table>
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
                colspan: 6
            });
        });
    </script>
@endpush
