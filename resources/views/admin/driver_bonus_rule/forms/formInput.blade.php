@php
    // Defensive unwrap: the accessor hands back a stdClass, but a value coming
    // straight from a failed validation round-trip is still a string.
    $nameTranslations = isset($row)
        ? (is_string($row->name) ? json_decode($row->name, true) : (array) $row->name)
        : [];
    $defaultCode = getDefaultLanguage('code');
    $readonly = Route::is('*.show');

    $currentBasis = old('basis', isset($row) ? $row->basis->value : \App\Modules\Driver\Enums\BonusBasis::PerOrder->value);

    // Old input wins on a failed save, so a rejected form does not lose the
    // tier rows somebody typed.
    $oldMins = old('tier_min_orders');
    $oldAmounts = old('tier_amounts');

    if (is_array($oldMins)) {
        $tierRows = [];
        foreach ($oldMins as $i => $min) {
            $tierRows[] = ['min_orders' => $min, 'amount' => $oldAmounts[$i] ?? ''];
        }
    } else {
        $tierRows = isset($row)
            ? $row->tiers->map(fn ($t) => ['min_orders' => $t->min_orders, 'amount' => $t->amount])->all()
            : [];
    }
@endphp

<div class="row g-3">
    <div class="col-md-6">
        <div class="form-group">
            <label for="rule-name" class="form-label">{{ __('Name') }}</label>
            <div class="controls">
                {{-- No `required`: the rule is «a name in at least one
                     language», which no single attribute can express, and
                     putting it on the default language would have the browser
                     refuse a save the server accepts. --}}
                <input type="text" name="name[{{ $defaultCode }}]" class="form-control" id="rule-name"
                    {{ $readonly ? 'disabled' : '' }}
                    placeholder="{{ __('e.g. Standard terms') }}"
                    value="{{ $nameTranslations[$defaultCode] ?? '' }}">
                <div class="form-text">{{ __('What you will call this when you put a driver on it.') }}</div>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="form-group">
            <label for="rule-status" class="form-label">{{ __('Status') }}</label>
            <div class="controls">
                <select name="status" id="rule-status" class="form-select" {{ $readonly ? 'disabled' : '' }}>
                    <option value="active" {{ old('status', $row->status ?? 'active') === 'active' ? 'selected' : '' }}>
                        {{ __('active') }}
                    </option>
                    <option value="inactive" {{ old('status', $row->status ?? '') === 'inactive' ? 'selected' : '' }}>
                        {{ __('inactive') }}
                    </option>
                </select>
                {{-- Switching a rule off is how you stop paying under it without
                     walking every driver on it. --}}
                <div class="form-text">{{ __('A switched-off rule pays nothing, for every driver on it.') }}</div>
            </div>
        </div>
    </div>
</div>

{{-- ---------------------------------------------------------------- immediate --}}
<div class="row g-3 border rounded p-3 mb-3 mt-1">
    <h5 class="mb-1">{{ __('Paid as they work') }}</h5>
    <p class="text-muted small">
        {{ __('Added to the driver wallet as pending, and released when the order completes.') }}
        {{ __('Leave the amount at zero for a rule that only pays a monthly bonus.') }}
    </p>

    <div class="col-md-5">
        <div class="form-group">
            <label for="rule-basis" class="form-label">{{ __('Based on') }}</label>
            <div class="controls">
                <select name="basis" id="rule-basis" class="form-select" {{ $readonly ? 'disabled' : '' }}>
                    @foreach ($bases as $case)
                        <option value="{{ $case->value }}" @selected($currentBasis === $case->value)>
                            {{ __($case->label()) }}
                        </option>
                    @endforeach
                </select>
                <div class="form-text" id="basis-hint"></div>
            </div>
        </div>
    </div>

    {{-- Two boxes for one question. The basis decides which is being asked, and
         the service nulls whichever does not apply so a rule can never carry
         two answers. --}}
    <div class="col-md-4" id="amount-field">
        <div class="form-group">
            <label for="rule-amount" class="form-label">{{ __('Amount') }}</label>
            <div class="controls">
                <input type="number" step="0.01" min="0" name="amount" id="rule-amount" class="form-control"
                    {{ $readonly ? 'disabled' : '' }}
                    value="{{ old('amount', $row->amount ?? '') }}">
                <div class="form-text">{{ __('In :currency.', ['currency' => appCurrency()]) }}</div>
            </div>
        </div>
    </div>

    <div class="col-md-4" id="rate-field">
        <div class="form-group">
            <label for="rule-rate" class="form-label">{{ __('Share of the delivery fee') }}</label>
            <div class="input-group">
                <input type="number" step="0.01" min="0" max="100" name="rate" id="rule-rate" class="form-control"
                    {{ $readonly ? 'disabled' : '' }}
                    value="{{ old('rate', $row->rate ?? '') }}">
                <span class="input-group-text">%</span>
            </div>
            <div class="form-text">{{ __('Split across the four journeys of an order.') }}</div>
        </div>
    </div>
</div>

{{-- ----------------------------------------------------------------- monthly --}}
<div class="row g-3 border rounded p-3 mb-3">
    <h5 class="mb-1">{{ __('Monthly targets') }}</h5>
    <p class="text-muted small">
        {{ __('Delivered this many orders in a calendar month, get this much.') }}
        <strong>{{ __('The highest target reached is paid, never the sum of them.') }}</strong>
        {{ __('Leave empty for a rule with no monthly bonus.') }}
    </p>

    <div class="col-12">
        <div id="tier-rows">
            @forelse ($tierRows as $tier)
                <div class="row g-2 align-items-end mb-2 tier-row">
                    <div class="col-md-4">
                        <label class="form-label small">{{ __('Orders delivered') }}</label>
                        <input type="number" min="1" step="1" name="tier_min_orders[]" class="form-control"
                            {{ $readonly ? 'disabled' : '' }} value="{{ $tier['min_orders'] }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">{{ __('Bonus') }}</label>
                        <input type="number" min="0" step="0.01" name="tier_amounts[]" class="form-control"
                            {{ $readonly ? 'disabled' : '' }} value="{{ $tier['amount'] }}">
                    </div>
                    @unless ($readonly)
                        <div class="col-md-2">
                            <button type="button" class="btn btn-outline-danger btn-sm js-remove-tier">
                                {{ __('Remove') }}
                            </button>
                        </div>
                    @endunless
                </div>
            @empty
                @unless ($readonly)
                    <div class="row g-2 align-items-end mb-2 tier-row">
                        <div class="col-md-4">
                            <label class="form-label small">{{ __('Orders delivered') }}</label>
                            <input type="number" min="1" step="1" name="tier_min_orders[]" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">{{ __('Bonus') }}</label>
                            <input type="number" min="0" step="0.01" name="tier_amounts[]" class="form-control">
                        </div>
                        <div class="col-md-2">
                            <button type="button" class="btn btn-outline-danger btn-sm js-remove-tier">
                                {{ __('Remove') }}
                            </button>
                        </div>
                    </div>
                @endunless
            @endforelse
        </div>

        @unless ($readonly)
            <button type="button" class="btn btn-outline-secondary btn-sm" id="add-tier">
                <i class="fa fa-plus"></i> {{ __('Add a target') }}
            </button>
        @endunless
    </div>
</div>

{{-- ------------------------------------------------------------------- gates --}}
<div class="row g-3 border rounded p-3 mb-3">
    <h5 class="mb-1">{{ __('Quality conditions') }}</h5>
    <p class="text-muted small">
        {{ __('Checked at the end of the month, on the monthly bonus only. A driver who misses any of them keeps their per-order bonus and loses the monthly one.') }}
        <strong>{{ __('Leave a box empty to not apply that condition.') }}</strong>
        {{ __('A bonus paid on volume alone pays a driver to rush.') }}
    </p>

    <div class="col-md-4">
        <div class="form-group">
            <label for="rule-ontime" class="form-label">{{ __('Minimum on-time rate') }}</label>
            <div class="input-group">
                <input type="number" step="0.01" min="0" max="100" name="min_on_time_rate" id="rule-ontime"
                    class="form-control" {{ $readonly ? 'disabled' : '' }}
                    value="{{ old('min_on_time_rate', $row->min_on_time_rate ?? '') }}">
                <span class="input-group-text">%</span>
            </div>
            <div class="form-text">{{ __('Journeys finished before their due time.') }}</div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="form-group">
            <label for="rule-rating" class="form-label">{{ __('Minimum delivery rating') }}</label>
            <div class="controls">
                <input type="number" step="0.01" min="1" max="5" name="min_delivery_rating" id="rule-rating"
                    class="form-control" {{ $readonly ? 'disabled' : '' }}
                    value="{{ old('min_delivery_rating', $row->min_delivery_rating ?? '') }}">
                {{-- The delivery score only. A driver must not lose their bonus
                     because a laundry ironed a shirt badly. --}}
                <div class="form-text">{{ __('Out of 5, and the delivery score only — never the laundry’s.') }}</div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="form-group">
            <label for="rule-failed" class="form-label">{{ __('Most failed journeys allowed') }}</label>
            <div class="controls">
                <input type="number" step="1" min="0" name="max_failed_tasks" id="rule-failed"
                    class="form-control" {{ $readonly ? 'disabled' : '' }}
                    value="{{ old('max_failed_tasks', $row->max_failed_tasks ?? '') }}">
                <div class="form-text">{{ __('Zero is a real rule here, not the same as empty.') }}</div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
    <script>
        $(document).ready(function () {
            const HINTS = {
                per_order: @json(__('One payment per order, on the handover to the customer.')),
                per_task: @json(__('Paid on each of the four journeys an order makes.')),
                percent_delivery_fee: @json(__('Follows distance, because the delivery fee already does.')),
            };

            // The amount box and the percentage box are the same question asked
            // two ways. Showing both invites somebody to fill both, and only one
            // of them would ever be read.
            function syncBasis() {
                const basis = $('#rule-basis').val();
                const flat = basis !== 'percent_delivery_fee';

                $('#amount-field').toggle(flat);
                $('#rate-field').toggle(!flat);
                $('#basis-hint').text(HINTS[basis] || '');
            }

            $('#rule-basis').on('change', syncBasis);
            syncBasis();

            $('#add-tier').on('click', function () {
                const row = $('#tier-rows .tier-row').first().clone();
                row.find('input').val('');
                $('#tier-rows').append(row);
            });

            // Delegated: rows are cloned in after page load, so a handler bound
            // to the buttons themselves would miss every row added since.
            $(document).on('click', '.js-remove-tier', function () {
                const rows = $('#tier-rows .tier-row');

                if (rows.length > 1) {
                    $(this).closest('.tier-row').remove();
                } else {
                    // Never remove the last row — an empty container has nothing
                    // for "Add a target" to clone.
                    rows.find('input').val('');
                }
            });
        });
    </script>
@endpush
