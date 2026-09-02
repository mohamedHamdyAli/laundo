@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Moderators') }}</h5>
        @if (canDo('moderator.create'))
            <a href="{{ route('admin.moderator.create') }}" class="btn-add">
                <i class="fa fa-plus"></i> {{ __('Add Moderator') }}
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
                                <input type="text" id="moderatorSearchInput" name="moderatorSearch"
                                    value="{{ request('moderatorSearch') }}" class="form-control"
                                    placeholder="Search Moderator...">
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
                            <span>{{ __('Phone') }}</span>
                            <span>{{ __('Role') }}</span>
                            <span>{{ __('Status') }}</span>
                            <span class="text-end">{{ __('Action') }}</span>
                        </div>

                        <div class="data-stack" id="moderator-table-body" style="--stack-cols: {{ $stackCols }}">
                            @include('admin.moderator.partials._moderator_table_body', ['moderators' => $moderators])
                        </div>

                        </div>

                        <div id="pagination-wrapper">
                            {{ $moderators->withQueryString()->links() }}
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
                inputSelector: '#moderatorSearchInput',
                tableBodySelector: '#moderator-table-body',
                paginationWrapperSelector: '#pagination-wrapper',
                url: "{{ route('admin.moderator.search') }}",
                // Card rows, not a table: the helper's default
                // <tr><td colspan> failure message would be stray
                // markup here.
                errorHtml: '<div class="stack-empty text-danger">Error during search</div>'
            });
        });
    </script>
@endpush
