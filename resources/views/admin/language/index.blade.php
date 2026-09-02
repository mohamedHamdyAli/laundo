@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Languages') }}</h5>
        @if (canDo('language.create'))
        <a href="{{ route('admin.language.create') }}" class="btn-add">
            <i class="fa fa-plus"></i>{{ __('Add Language') }}
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
                                <input type="text" id="languageSearchInput" name="languageSearch"
                                    value="{{ request('languageSearch') }}" class="form-control"
                                    placeholder="Search Language...">
                            </div>
                        </div>
                        <div id="search-info" class="mb-3 small text-muted text-end" style="display: none;"></div>

                        @php
                            // Shared by the label strip and every row, so the labels
                            // sit exactly over the fields they name.
                            $stackCols = 'minmax(3.8rem,4rem) minmax(9rem,1.3fr) minmax(6rem,.8fr) minmax(8rem,1fr) minmax(6rem,auto)';
                        @endphp

                        <div class="stack-head" style="--stack-cols: {{ $stackCols }}">
                            <span>{{ __('Icon') }}</span>
                            <span>{{ __('Name') }}</span>
                            <span>{{ __('Code') }}</span>
                            <span>{{ __('Created At') }}</span>
                            <span class="text-end">{{ __('Action') }}</span>
                        </div>

                        <div class="data-stack" id="language-table-body" style="--stack-cols: {{ $stackCols }}">
                            @include('admin.language.partials._language_table_body', [ 'language' => $languages, ])
                        </div>

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
                // Card rows, not a table: the helper's default
                // <tr><td colspan> failure message would be stray
                // markup here.
                errorHtml: '<div class="stack-empty text-danger">Error during search</div>'
            });
        });
    </script>
@endpush
