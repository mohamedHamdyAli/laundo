@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Bonus Rules') }}</h5>
        @if (canDo('driver_bonus_rule.create'))
            <a href="{{ route('admin.driver_bonus_rule.create') }}" class="btn-add">
                <i class="fa fa-plus"></i> {{ __('Add Bonus Rule') }}
            </a>
        @endif
    </div>

    <section class="section">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <p class="text-muted small">
                            {{-- Said on the screen, because it is the opposite of
                                 what this platform used to do: every driver was
                                 paid a hardcoded 20% of every delivery fee. --}}
                            {{ __('A driver earns a bonus only while they are on a rule. A driver on none earns nothing.') }}
                            {{ __('Salaries are paid outside this system and are not recorded here.') }}
                        </p>

                        <div class="d-flex justify-content-end mb-3">
                            <div class="input-group" style="max-width: 350px;">
                                <input type="text" id="ruleSearchInput" class="form-control"
                                    placeholder="{{ __('Search rules...') }}">
                            </div>
                        </div>

                        <div class="table-responsive">
                            @php
                                // Shared by the label strip and every row, so the labels
                                // sit exactly over the fields they name.
                                $stackCols = 'minmax(9rem,1.2fr) minmax(9rem,1.1fr) minmax(8rem,1fr) minmax(9rem,1.2fr) minmax(5rem,auto) minmax(6rem,auto) minmax(7rem,auto)';
                            @endphp

                            <div class="stack-head" style="--stack-cols: {{ $stackCols }}">
                                <span>{{ __('Name') }}</span>
                                <span>{{ __('Immediate') }}</span>
                                <span>{{ __('Monthly') }}</span>
                                <span>{{ __('Conditions') }}</span>
                                <span>{{ __('Drivers') }}</span>
                                <span>{{ __('Status') }}</span>
                                <span class="text-end">{{ __('Action') }}</span>
                            </div>

                            <div class="data-stack" id="rule-table-body" style="--stack-cols: {{ $stackCols }}">
                                @include('admin.driver_bonus_rule.partials._driver_bonus_rule_table_body', ['rules' => $rules])
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
                inputSelector: '#ruleSearchInput',
                tableBodySelector: '#rule-table-body',
                paginationWrapperSelector: '#pagination-wrapper',
                url: "{{ route('admin.driver_bonus_rule.search') }}",
                // Card rows, not a table: the helper's default
                // <tr><td colspan> failure message would be stray markup here.
                errorHtml: '<div class="stack-empty text-danger">Error during search</div>'
            });
        });
    </script>
@endpush
