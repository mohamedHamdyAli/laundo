@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('FAQ') }}</h5>
        @if (canDo('faq.create'))
            <a href="{{ route('admin.faq.create') }}" class="btn-add">
                <i class="fa fa-plus"></i> {{ __('Add Question') }}
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
                                <input type="text" id="faqSearchInput" class="form-control"
                                    placeholder="{{ __('Search FAQ...') }}">
                            </div>
                        </div>

                        <div class="table-responsive">
                        @php
                            // Shared by the label strip and every row, so the labels
                            // sit exactly over the fields they name.
                            $stackCols = 'minmax(11rem,1.3fr) minmax(12rem,1.8fr) minmax(7rem,auto) minmax(7rem,auto) minmax(6rem,auto)';
                        @endphp

                        <div class="stack-head" style="--stack-cols: {{ $stackCols }}">
                            <span>{{ __('Question') }}</span>
                            <span>{{ __('Answer') }}</span>
                            <span>{{ __('Audience') }}</span>
                            <span>{{ __('Status') }}</span>
                            <span class="text-end">{{ __('Action') }}</span>
                        </div>

                        <div class="data-stack" id="faq-table-body" style="--stack-cols: {{ $stackCols }}">
                            @include('admin.faq.partials._faq_table_body', ['faqs' => $faqs])
                        </div>

                        </div>

                        <div id="pagination-wrapper">
                            {{ $faqs->links() }}
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
                inputSelector: '#faqSearchInput',
                tableBodySelector: '#faq-table-body',
                paginationWrapperSelector: '#pagination-wrapper',
                url: "{{ route('admin.faq.search') }}",
                // Card rows, not a table: the helper's default
                // <tr><td colspan> failure message would be stray
                // markup here.
                errorHtml: '<div class="stack-empty text-danger">Error during search</div>'
            });
        });
    </script>
@endpush
