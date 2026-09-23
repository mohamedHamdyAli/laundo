@extends('layouts.main')

@section('content')
    @php
        // Counted here rather than passed in, so the badge cannot go stale on
        // the AJAX search path — which re-renders the body and not the header.
        $pendingApplications = canDo('laundry.update')
            ? \App\Modules\Laundry\Models\Laundry::withoutGlobalScopes()->pending()->count()
            : 0;
    @endphp

    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Laundries') }}</h5>
        <div class="d-flex align-items-center gap-2">
            {{-- Only when there is something to look at. A permanent link to an
                 empty screen is one an operator learns to ignore, and this one
                 has to be noticed on the day it is not empty. --}}
            @if ($pendingApplications > 0)
                <a href="{{ route('admin.laundry.pending') }}" class="btn btn-warning btn-sm">
                    <i class="bi bi-hourglass-split"></i>
                    {{ __('Applications') }}
                    {{-- Not `bg-dark`. The vendor template redefines
                         `--bs-dark-rgb` to the *page background* at :root, so
                         that class paints a near-white pill — and it sets the
                         colour with `!important`, so no override in theme.css
                         can win it. The class is a trap here, not a shortcut. --}}
                    <span class="badge pending-count ms-1">{{ $pendingApplications }}</span>
                </a>
            @endif
            @if (canDo('laundry.create'))
                <a href="{{ route('admin.laundry.create') }}" class="btn-add">
                    <i class="fa fa-plus"></i> {{ __('Add Laundry') }}
                </a>
            @endif
        </div>
    </div>

    <section class="section">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-end mb-3">
                            <div class="input-group" style="max-width: 350px;">
                                <input type="text" id="laundrySearchInput" name="laundrySearch"
                                    value="{{ request('laundrySearch') }}" class="form-control"
                                    placeholder="{{ __('Search Laundry...') }}">
                            </div>
                        </div>

                        <div class="table-responsive">
                        @php
                            // Shared by the label strip and every row, so the labels
                            // sit exactly over the fields they name.
                            $stackCols = 'minmax(3.8rem,4rem) minmax(9rem,1.3fr) minmax(8rem,1fr) minmax(8rem,1fr) minmax(6rem,auto) minmax(7rem,auto) minmax(7rem,auto)';
                        @endphp

                        <div class="stack-head" style="--stack-cols: {{ $stackCols }}">
                            <span>{{ __('Logo') }}</span>
                            <span>{{ __('Name') }}</span>
                            <span>{{ __('Phone') }}</span>
                            <span>{{ __('City') }}</span>
                            <span>{{ __('Commission') }}</span>
                            <span>{{ __('Status') }}</span>
                            <span class="text-end">{{ __('Action') }}</span>
                        </div>

                        <div class="data-stack" id="laundry-table-body" style="--stack-cols: {{ $stackCols }}">
                            @include('admin.laundry.partials._laundry_table_body', ['laundries' => $laundries])
                        </div>

                        </div>

                        <div id="pagination-wrapper">
                            {{ $laundries->withQueryString()->links() }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    @if (canDo('setting.update'))
        {{-- «العمولة» — set what one laundry pays.

             A plain form, deliberately without `needs-validation`: that class is
             what form-validation.js binds its background submit to, and there is
             nothing typed here worth preserving across a failure. It posts, it
             redirects, the row shows the new rate. --}}
        <div class="modal fade" id="commissionModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form method="POST" id="commissionForm">
                    @csrf
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">{{ __('Commission') }}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"
                                aria-label="{{ __('Close') }}"></button>
                        </div>
                        <div class="modal-body">
                            <p class="text-muted small mb-3" id="commissionLaundryName"></p>

                            <label class="form-label">{{ __('Charges that apply') }}</label>

                            @php $attachable = \App\Modules\Payment\Controllers\CommissionRuleController::attachableRules(); @endphp

                            @forelse ($attachable as $rule)
                                <div class="form-check">
                                    <input class="form-check-input js-commission-rule" type="checkbox"
                                        name="commission_rule_ids[]" value="{{ $rule->id }}"
                                        id="commission-rule-{{ $rule->id }}">
                                    <label class="form-check-label" for="commission-rule-{{ $rule->id }}">
                                        {{ getLocalizedValueDashboard($rule, 'name') }}
                                        <span class="text-muted">— {{ $rule->explain() }}</span>
                                    </label>
                                </div>
                            @empty
                                <p class="text-muted small mb-0">
                                    {{ __('No charges exist yet.') }}
                                    <a href="{{ route('admin.commission_rule.index') }}">{{ __('Commissions') }}</a>
                                </p>
                            @endforelse

                            <div class="form-text mt-2">
                                {{ __('Ticked charges add together and are credited to the super admin wallet; the rest goes to this laundry.') }}
                                <strong>{{ __('Tick nothing and this laundry is charged nothing.') }}</strong>
                                {{ __('There is no general rate behind it — attaching a charge of 0 says the same thing on the record, which is worth doing so nobody later reads the blank as an oversight.') }}
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
                inputSelector: '#laundrySearchInput',
                tableBodySelector: '#laundry-table-body',
                paginationWrapperSelector: '#pagination-wrapper',
                url: "{{ route('admin.laundry.search') }}",
                // Card rows, not a table: the helper's default
                // <tr><td colspan> failure message would be stray
                // markup here.
                errorHtml: '<div class="stack-empty text-danger">Error during search</div>'
            });

            @if (canDo('setting.update'))
            // Delegated, not bound to the buttons directly. setupAjaxSearch
            // replaces the whole row container on every keystroke, so a handler
            // attached to the buttons themselves works until somebody searches
            // and then silently stops — the same trap .toggle-status avoids.
            //
            // Gated like the button and the modal it opens. A handler shipped to
            // somebody who is never shown the button is dead code, and it makes
            // the page claim a capability the routes refuse.
            $(document).on('click', '.js-commission-btn', function () {
                const $btn = $(this);

                $('#commissionForm').attr('action', $btn.data('action'));
                $('#commissionLaundryName').text($btn.data('name'));

                // Exactly what this laundry already carries, and nothing
                // pre-ticked otherwise: opening the dialog and pressing Save
                // would then pin a laundry that follows the general rate onto
                // today's value of it.
                const attached = String($btn.attr('data-rules') || '')
                    .split(',')
                    .filter(Boolean);

                $('.js-commission-rule').each(function () {
                    $(this).prop('checked', attached.includes($(this).val()));
                });

                bootstrap.Modal.getOrCreateInstance(document.getElementById('commissionModal')).show();
            });
            @endif
        });
    </script>
@endpush
