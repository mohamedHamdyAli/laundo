@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('FAQ') }}</h5>
        @if (canDo('faq.create'))
            <a href="{{ route('admin.faq.create') }}" class="badge alert-info primary-background-color">
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
                            <table class="table table-borderless table-striped" id="table_list">
                                <thead class="table-light">
                                    <tr>
                                        <th>{{ __('Order') }}</th>
                                        <th>{{ __('Question') }}</th>
                                        <th>{{ __('Answer') }}</th>
                                        <th>{{ __('Shown to') }}</th>
                                        <th>{{ __('Status') }}</th>
                                        <th class="text-center">{{ __('Action') }}</th>
                                    </tr>
                                </thead>
                                <tbody id="faq-table-body">
                                    @include('admin.faq.partials._faq_table_body', ['faqs' => $faqs])
                                </tbody>
                            </table>
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
                colspan: 6
            });
        });
    </script>
@endpush
