@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Languages') }}</h5>
        <a href="{{ route('admin.language.create') }}" class="badge alert-info primary-background-color">
            <i class="fa fa-plus"></i>{{ __('Add Language') }}
        </a>
    </div>
    <section class="section">
        <div class="row">
            <div class="col-md-12">
                <div class="card">

                    <div class="card-body">
                        <div class="d-flex justify-content-end mb-3">
                            <div class="input-group" style="max-width: 350px;">
                                <input type="text" id="languageSearchInput" name="languageSearch"
                                    value="{{ request('languageSearch') }}" class="form-control"
                                    placeholder="Search Language...">
                            </div>
                        </div>
                        <div id="search-info" class="mb-3 small text-muted text-end" style="display: none;"></div>

                        <table class="table table-borderless table-striped" id="table_list">
                            <thead class="table-light">
                                <tr>
                                    <th>{{ __('Icon') }}</th>
                                    <th>{{ __('ID') }}</th>
                                    <th>{{ __('Name') }}</th>
                                    <th>{{ __('Name En') }}</th>
                                    <th>{{ __('Code') }}</th>
                                    <th>{{ __('Country Code') }}</th>
                                    <th>{{ __('Created At') }}</th>
                                    <th class="text-center">{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody id="language-table-body">
                                @include('admin.language.partials._language_table_body', [
                                    'language' => $languages,
                                ])

                            </tbody>
                        </table>

                        <div id="pagination-wrapper">
                            {{ $languages->links() }}
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
                inputSelector: '#languageSearchInput',
                tableBodySelector: '#language-table-body',
                paginationWrapperSelector: '#pagination-wrapper',
                url: "{{ route('admin.language.search') }}",
                colspan: 8
            });
        });
    </script>
@endpush
