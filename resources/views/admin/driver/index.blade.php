@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Drivers') }}</h5>
        @if (canDo('driver.create'))
            <a href="{{ route('admin.driver.create') }}" class="btn-add">
                <i class="fa fa-plus"></i> {{ __('Add Driver') }}
            </a>
        @endif
    </div>

    <section class="section">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <p class="text-muted small">
                            {{ __('Driver accounts are created here. There is no self-registration in the driver app.') }}
                        </p>

                        <div class="d-flex justify-content-end mb-3">
                            <div class="input-group" style="max-width: 350px;">
                                <input type="text" id="driverSearchInput" class="form-control"
                                    placeholder="{{ __('Search by name or phone...') }}">
                            </div>
                        </div>

                        <div class="table-responsive">
                        @php
                            // Shared by the label strip and every row, so the labels
                            // sit exactly over the fields they name.
                            $stackCols = 'minmax(3.8rem,4rem) minmax(9rem,1.3fr) minmax(7rem,.9fr) minmax(8rem,1.1fr) minmax(7rem,auto) minmax(8rem,1fr) minmax(7rem,auto) minmax(7rem,auto)';
                        @endphp

                        <div class="stack-head" style="--stack-cols: {{ $stackCols }}">
                            <span>{{ __('Image') }}</span>
                            <span>{{ __('Name') }}</span>
                            <span>{{ __('Vehicle') }}</span>
                            <span>{{ __('Areas') }}</span>
                            <span>{{ __('Availability') }}</span>
                            <span>{{ __('Bonus') }}</span>
                            <span>{{ __('Status') }}</span>
                            <span class="text-end">{{ __('Action') }}</span>
                        </div>

                        <div class="data-stack" id="driver-table-body" style="--stack-cols: {{ $stackCols }}">
                            @include('admin.driver.partials._driver_table_body', ['drivers' => $drivers])
                        </div>

                        </div>

                        <div id="pagination-wrapper">
                            {{ $drivers->withQueryString()->links() }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    @if (canDo('setting.update'))
        {{-- «البونس» — put one driver on a rule, or take them off it.

             A plain form, deliberately without `needs-validation`: that class is
             what form-validation.js binds its background submit to, and there is
             nothing typed here worth preserving across a failure. --}}
        <div class="modal fade" id="bonusModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form method="POST" id="bonusForm">
                    @csrf
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">{{ __('Bonus') }}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"
                                aria-label="{{ __('Close') }}"></button>
                        </div>
                        <div class="modal-body">
                            <p class="text-muted small mb-3" id="bonusDriverName"></p>
                            <label for="bonusRuleId" class="form-label">{{ __('Bonus rule') }}</label>
                            <select name="bonus_rule_id" id="bonusRuleId" class="form-select">
                                <option value="">{{ __('No bonus') }}</option>
                                @foreach (\App\Modules\Driver\Controllers\DriverBonusRuleController::assignableRules() as $rule)
                                    <option value="{{ $rule->id }}">
                                        {{ getLocalizedValueDashboard($rule, 'name') }} — {{ $rule->explainImmediate() }}
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-text">
                                {{ __('Only active rules are listed — putting a driver on a switched-off rule would look like it paid and pay nothing.') }}
                                <strong>{{ __('«No bonus» means exactly that: this driver earns nothing on top of their salary.') }}</strong>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                {{ __('Cancel') }}
                            </button>
                            <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    @endif
@endsection

@push('scripts')
    <script>
        $(document).ready(function () {
            setupAjaxSearch({
                inputSelector: '#driverSearchInput',
                tableBodySelector: '#driver-table-body',
                paginationWrapperSelector: '#pagination-wrapper',
                url: "{{ route('admin.driver.search') }}",
                // Card rows, not a table: the helper's default
                // <tr><td colspan> failure message would be stray
                // markup here.
                errorHtml: '<div class="stack-empty text-danger">Error during search</div>'
            });

            @if (canDo('setting.update'))
            // Delegated, not bound to the buttons directly. setupAjaxSearch
            // replaces the whole row container on every keystroke, so a handler
            // attached to the buttons themselves works until somebody searches
            // and then silently stops.
            $(document).on('click', '.js-bonus-btn', function () {
                const $btn = $(this);

                $('#bonusForm').attr('action', $btn.data('action'));
                $('#bonusDriverName').text($btn.data('name'));
                $('#bonusRuleId').val($btn.attr('data-rule') || '');

                bootstrap.Modal.getOrCreateInstance(document.getElementById('bonusModal')).show();
            });
            @endif
        });
    </script>
@endpush
