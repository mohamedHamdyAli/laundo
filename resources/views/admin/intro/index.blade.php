@extends('layouts.main')


@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Intro') }}</h5>
        @if (canDo('intro.create'))
        <a href="{{ route('admin.intro.create') }}" class="badge alert-info primary-background-color">
            <i class="fa fa-plus"></i>{{ __('Add Intro') }}
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
                                <input type="text" id="introSearchInput" name="introSearch"
                                    value="{{ request('introSearch') }}" class="form-control" placeholder="Search Intro...">
                            </div>
                        </div>
                        <div id="search-info" class="mb-3 small text-muted text-end" style="display: none;"></div>

                        <table class="table table-borderless table-striped" id="table_list">
                            <thead class="table-light">
                                <tr>
                                    <th>{{ __('Image') }}</th>
                                    <th>{{ __('ID') }}</th>
                                    <th>{{ __('Title') }}</th>
                                    <th>{{ __('Description') }}</th>
                                    <th>{{ __('Order') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th>{{ __('Created At') }}</th>
                                    <th class="text-center">{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody id="intro-table-body">
                                @include('admin.intro.partials._intro_table_body', [
                                    'intros' => $intros,
                                ])
                            </tbody>
                        </table>

                        <div id="pagination-wrapper">
                            {{ $intros->links() }}
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
                inputSelector: '#introSearchInput',
                tableBodySelector: '#intro-table-body',
                paginationWrapperSelector: '#pagination-wrapper',
                url: "{{ route('admin.intro.search') }}",
                colspan: 8
            });
        });
    </script>
@endpush
