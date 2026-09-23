@extends('layouts.main')

@section('content')
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">{{ __('Laundry Revenue') }}</h5>
        <span class="text-muted small">{{ $window->label() }}</span>
    </div>

    <section class="section">
        {{-- The five headline figures, over the whole window rather than over
             the page. Adding up the fifteen rows below would make these describe
             page one, which is the sort of wrong number nobody checks. --}}
        <div class="row mb-3">
            <div class="col-md-3 col-xl">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1">{{ __('Collected from customers') }}</h6>
                    <h3 class="mb-0">{{ moneyFormat($summary['user_paid']) }}</h3>
                    <small class="text-muted">
                        {{ trans_choice(':count order|:count orders', $summary['orders'], ['count' => $summary['orders']]) }}
                    </small>
                </div></div>
            </div>
            <div class="col-md-3 col-xl">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1">{{ __('Tax collected') }}</h6>
                    <h3 class="mb-0">{{ moneyFormat($summary['tax']) }}</h3>
                    {{-- Never divided and never commissioned: it is the state's,
                         passing through on its way to the treasury. --}}
                    <small class="text-muted">{{ __('Owed to the state, never split') }}</small>
                </div></div>
            </div>
            <div class="col-md-3 col-xl">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1">{{ __('Fee from customers') }}</h6>
                    <h3 class="mb-0">{{ moneyFormat($summary['platform_fee']) }}</h3>
                    {{-- Two cards rather than one total: the two are paid by different
                         people, and added together they answer neither «what are
                         we charging our customers» nor «what are we charging our
                         laundries». --}}
                    <small class="text-muted">{{ __('Folded into the prices they were shown') }}</small>
                </div></div>
            </div>
            <div class="col-md-3 col-xl">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1">{{ __('Commission from laundries') }}</h6>
                    <h3 class="mb-0">{{ moneyFormat($summary['commission']) }}</h3>
                    <small class="text-muted">{{ __('Taken out of what they earned') }}</small>
                </div></div>
            </div>
            <div class="col-md-3 col-xl">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1">{{ __('Laundries receive') }}</h6>
                    <h3 class="mb-0">{{ moneyFormat($summary['laundries_receive']) }}</h3>
                    <small class="text-muted">
                        @if ($summary['deducted'] > 0)
                            {{ __('after') }} {{ moneyFormat($summary['deducted']) }} {{ __('deducted') }}
                        @else
                            {{ __('After deductions') }}
                        @endif
                    </small>
                </div></div>
            </div>
            <div class="col-md-3 col-xl">
                <div class="card"><div class="card-body">
                    <h6 class="text-muted mb-1">{{ __('Laundries trading') }}</h6>
                    <h3 class="mb-0">{{ $summary['laundries'] }}</h3>
                    {{-- Counted off the orders, not off the laundries table: the
                         figure worth showing is how many actually traded in this
                         window, not how many rows exist. --}}
                    <small class="text-muted">{{ __('With at least one order in this window') }}</small>
                </div></div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                {{-- A plain GET form for the window, like the reports: a screen
                     you cannot link to or bookmark is one somebody has to
                     re-find every morning. The search box beside it is AJAX and
                     deliberately outside this form — typing must not submit it. --}}
                <form method="GET" class="d-flex gap-2 align-items-end flex-wrap mb-3">
                    <div>
                        <label class="form-label mb-1 small">{{ __('Year') }}</label>
                        <select name="year" class="form-select form-select-sm" style="min-width: 8rem;">
                            <option value="all" @selected($window->year === 'all')>{{ __('All years') }}</option>
                            @foreach ($years as $year)
                                <option value="{{ $year }}" @selected($window->year === $year)>{{ $year }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="form-label mb-1 small">{{ __('Month') }}</label>
                        <select name="month" class="form-select form-select-sm" style="min-width: 9rem;">
                            <option value="all" @selected($window->month === 'all')>{{ __('All months') }}</option>
                            @for ($month = 1; $month <= 12; $month++)
                                {{-- `locale()` explicitly rather than relying on
                                     Carbon's global: `Carbon::setLocale()` is
                                     only ever called inside `getCurrentLocale()`,
                                     which reads the API's `lang` header and does
                                     not run on a panel request. Without this the
                                     months read «January» inside an Arabic
                                     panel. --}}
                                <option value="{{ $month }}" @selected($window->month === (string) $month)>
                                    {{ \Illuminate\Support\Carbon::create(null, $month, 1)->locale(app()->getLocale())->translatedFormat('F') }}
                                </option>
                            @endfor
                        </select>
                    </div>
                    <div>
                        <label class="form-label mb-1 small">{{ __('From') }}</label>
                        <input type="date" name="from" class="form-control form-control-sm"
                            value="{{ $window->explicitDates ? $window->from->toDateString() : '' }}">
                    </div>
                    <div>
                        <label class="form-label mb-1 small">{{ __('To') }}</label>
                        <input type="date" name="to" class="form-control form-control-sm"
                            value="{{ $window->explicitDates ? $window->to->toDateString() : '' }}">
                    </div>

                    <button type="submit" class="btn btn-sm btn-primary">{{ __('Apply') }}</button>
                    <a href="{{ route('admin.laundry_revenue.index') }}"
                        class="btn btn-sm btn-outline-secondary">{{ __('Reset') }}</a>

                    <a href="{{ route('admin.laundry_revenue.export', request()->only(['year', 'month', 'from', 'to'])) }}"
                        class="btn btn-sm btn-outline-success ms-auto">
                        <i class="fa fa-download"></i> {{ __('Export CSV') }}
                    </a>
                </form>

                {{-- Dates override the dropdowns, so say so rather than leaving
                     somebody to work out why «2026 / March» is showing April. --}}
                @if ($window->explicitDates)
                    <p class="text-muted small">
                        {{ __('Showing the dates entered above; the year and month are ignored while they are set.') }}
                    </p>
                @endif

                <div class="d-flex justify-content-end mb-3">
                    <div class="input-group" style="max-width: 340px;">
                        <input type="text" id="laundryRevenueSearchInput" class="form-control"
                            value="{{ $search }}"
                            placeholder="{{ __('Search by laundry name, email or city...') }}">
                    </div>
                </div>

                <div class="table-responsive">
                    @php
                        // Shared by the label strip and every row, so the labels
                        // sit exactly over the fields they name.
                        $stackCols = 'minmax(10rem,1.4fr) minmax(7rem,auto) minmax(8rem,1fr) minmax(8rem,1fr) minmax(9rem,1.1fr) minmax(8rem,1fr) minmax(8rem,auto) minmax(6rem,auto)';
                    @endphp

                    <div class="stack-head" style="--stack-cols: {{ $stackCols }}">
                        <span>{{ __('Laundry') }}</span>
                        <span>{{ __('Orders') }}</span>
                        <span>{{ __('Customers paid') }}</span>
                        <span>{{ __('Platform earnings') }}</span>
                        <span>{{ __('Laundry entitled') }}</span>
                        <span>{{ __('Deducted') }}</span>
                        <span class="text-end">{{ __('Net payable') }}</span>
                        <span class="text-end">{{ __('Action') }}</span>
                    </div>

                    <div class="data-stack" id="laundry_revenue-table-body" style="--stack-cols: {{ $stackCols }}">
                        @include('admin.laundry_revenue.partials._laundry_revenue_table_body', ['rows' => $rows])
                    </div>
                </div>

                <div id="pagination-wrapper">
                    {{ $rows->withQueryString()->links() }}
                </div>
            </div>
        </div>
    </section>

    @if (canDo('setting.update'))
        {{-- «خصم» — money taken back off one laundry.

             Gated on `setting.update`, not `laundry.update`: a laundry owner
             holds the latter by design, and anything deciding what they are paid
             must hang off a permission they do not hold. Same boundary as the
             commission modal on the laundry list.

             Deliberately without `needs-validation`: that class is what
             form-validation.js binds its background submit to, and this posts,
             redirects and shows the new figure on the row. --}}
        <div class="modal fade" id="deductionModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form method="POST" id="deductionForm">
                    @csrf
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">{{ __('Add deduction') }}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"
                                aria-label="{{ __('Close') }}"></button>
                        </div>
                        <div class="modal-body">
                            <p class="text-muted small mb-3" id="deductionLaundryName"></p>

                            <div class="mb-3">
                                <label class="form-label" for="deductionAmount">
                                    {{ __('Amount to deduct') }} <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <input type="number" step="0.01" min="0.01" name="amount" id="deductionAmount"
                                        class="form-control" placeholder="0.00" required>
                                    <span class="input-group-text">{{ appCurrency() }}</span>
                                </div>
                            </div>

                            <div class="mb-2">
                                <label class="form-label" for="deductionReason">
                                    {{ __('Reason for deduction') }} <span class="text-danger">*</span>
                                </label>
                                <textarea name="reason" id="deductionReason" rows="3" class="form-control"
                                    minlength="3" maxlength="500" required
                                    placeholder="{{ __('e.g. Refund issued to a customer on this laundry\'s behalf') }}"></textarea>
                            </div>

                            {{-- Says plainly what it does and what it does not.
                                 The wallet is untouched: a deduction is a claim
                                 against what the laundry is paid, and a balance
                                 that has not settled yet cannot be debited. --}}
                            <div class="form-text">
                                {{ __('Recorded against this laundry and subtracted from what it is paid. The wallet balance is not touched.') }}
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                {{ __('Cancel') }}
                            </button>
                            <button type="submit" class="btn btn-primary">{{ __('Save deduction') }}</button>
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
                inputSelector: '#laundryRevenueSearchInput',
                tableBodySelector: '#laundry_revenue-table-body',
                paginationWrapperSelector: '#pagination-wrapper',
                url: "{{ route('admin.laundry_revenue.search') }}",
                // A function, not an object: read at request time so the window
                // survives typing. Passed as an object the values freeze at
                // page load and a search silently reports a different month.
                extraParams: () => ({
                    year: $('select[name="year"]').val(),
                    month: $('select[name="month"]').val(),
                    from: $('input[name="from"]').val(),
                    to: $('input[name="to"]').val(),
                }),
                // Card rows, not a table: the helper's default
                // <tr><td colspan> failure message would be stray markup here.
                errorHtml: '<div class="stack-empty text-danger">Error during search</div>'
            });

            @if (canDo('setting.update'))
            // Delegated, not bound to the buttons directly. setupAjaxSearch
            // replaces the whole row container on every keystroke, so a handler
            // attached to the buttons themselves works until somebody searches
            // and then silently stops.
            $(document).on('click', '.js-deduction-btn', function () {
                const $btn = $(this);

                $('#deductionForm').attr('action', $btn.data('action'));
                $('#deductionLaundryName').text($btn.data('name'));

                // Cleared every time. A figure left over from the previous
                // laundry is the one mistake this dialog can make that nobody
                // would notice before pressing Save.
                $('#deductionAmount').val('');
                $('#deductionReason').val('');

                // Opened from script rather than with `data-bs-toggle` on the
                // button, like the commission dialog: the attributes above have
                // to be read into the form *before* it appears, and a declarative
                // toggle races this handler.
                bootstrap.Modal.getOrCreateInstance(document.getElementById('deductionModal')).show();
            });
            @endif
        });
    </script>
@endpush
