@php
    // Defensive unwrap: the accessor hands back a stdClass, but a value coming
    // straight from a failed validation round-trip is still a string.
    $nameTranslations = isset($row)
        ? (is_string($row->name) ? json_decode($row->name, true) : (array) $row->name)
        : [];
    $defaultCode = getDefaultLanguage('code');
    $readonly = Route::is('*.show');

    $currentBasis = old('basis', isset($row) ? $row->basis->value : \App\Modules\Payment\Enums\CommissionBasis::Percent->value);

    $attached = old('laundry_ids', isset($row) ? $row->laundries->pluck('id')->all() : []);
@endphp

<div class="row g-3">
    <div class="col-md-6">
        <div class="form-group">
            <label for="commission-name" class="form-label">{{ __('Name') }}</label>
            <div class="controls">
                {{-- No `required`: the rule is «a name in at least one
                     language», which no single attribute can express, and
                     putting it on the default language would have the browser
                     refuse a save the server accepts. --}}
                <input type="text" name="name[{{ $defaultCode }}]" class="form-control" id="commission-name"
                    {{ $readonly ? 'disabled' : '' }}
                    placeholder="{{ __('e.g. Platform fee') }}"
                    value="{{ $nameTranslations[$defaultCode] ?? '' }}">
                <div class="form-text">
                    {{ __('This is the name the laundry sees on its own settlement line.') }}
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="form-group">
            <label for="commission-status" class="form-label">{{ __('Status') }}</label>
            <div class="controls">
                <select name="status" id="commission-status" class="form-select" {{ $readonly ? 'disabled' : '' }}>
                    <option value="active" {{ old('status', $row->status ?? 'active') === 'active' ? 'selected' : '' }}>
                        {{ __('active') }}
                    </option>
                    <option value="inactive" {{ old('status', $row->status ?? '') === 'inactive' ? 'selected' : '' }}>
                        {{ __('inactive') }}
                    </option>
                </select>
                <div class="form-text">{{ __('A switched-off charge bills nothing, for every laundry on it.') }}</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 border rounded p-3 mb-3 mt-1">
    <h5 class="mb-1">{{ __('What it takes') }}</h5>
    <p class="text-muted small">
        {{ __('Measured on the order total before tax. Tax is the state\'s money passing through, so it is never charged commission.') }}
        <strong>{{ __('A laundry can carry several charges and they add together.') }}</strong>
    </p>

    <div class="col-md-5">
        <div class="form-group">
            <label for="commission-basis" class="form-label">{{ __('Based on') }}</label>
            <div class="controls">
                <select name="basis" id="commission-basis" class="form-select" {{ $readonly ? 'disabled' : '' }}>
                    @foreach ($bases as $case)
                        <option value="{{ $case->value }}" @selected($currentBasis === $case->value)>
                            {{ __($case->label()) }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    {{-- Two boxes for one question. The basis decides which is being asked, and
         the service nulls whichever does not apply so a rule can never carry
         two answers. --}}
    <div class="col-md-4" id="commission-rate-field">
        <div class="form-group">
            <label for="commission-rate" class="form-label">{{ __('Share of the order') }}</label>
            <div class="input-group">
                <input type="number" step="0.01" min="0" max="100" name="rate" id="commission-rate"
                    class="form-control" {{ $readonly ? 'disabled' : '' }}
                    value="{{ old('rate', $row->rate ?? '') }}">
                <span class="input-group-text">%</span>
            </div>
        </div>
    </div>

    <div class="col-md-4" id="commission-amount-field">
        <div class="form-group">
            <label for="commission-amount" class="form-label">{{ __('Amount per order') }}</label>
            <div class="controls">
                <input type="number" step="0.01" min="0" name="amount" id="commission-amount"
                    class="form-control" {{ $readonly ? 'disabled' : '' }}
                    value="{{ old('amount', $row->amount ?? '') }}">
                <div class="form-text">{{ __('In :currency.', ['currency' => appCurrency()]) }}</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 border rounded p-3 mb-3">
    <h5 class="mb-1">{{ __('Which laundries pay it') }}</h5>
    <p class="text-muted small">
        {{ __('Tick as many as this charge applies to. A laundry with nothing ticked anywhere follows the general rate in Settings.') }}
        <strong>{{ __('A laundry that genuinely pays nothing needs a charge of 0, not an empty list.') }}</strong>
    </p>

    <div class="col-12">
        @forelse ($laundries as $laundry)
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" name="laundry_ids[]"
                    id="laundry-{{ $laundry->id }}" value="{{ $laundry->id }}"
                    {{ $readonly ? 'disabled' : '' }}
                    {{ in_array($laundry->id, (array) $attached) ? 'checked' : '' }}>
                <label class="form-check-label" for="laundry-{{ $laundry->id }}">
                    {{ getLocalizedValueDashboard($laundry, 'name') }}
                </label>
            </div>
        @empty
            <p class="text-muted small mb-0">{{ __('No data found') }}</p>
        @endforelse
    </div>
</div>

@push('scripts')
    <script>
        $(document).ready(function () {
            // The percentage box and the amount box are the same question asked
            // two ways. Showing both invites somebody to fill both, and only one
            // of them would ever be read.
            function syncBasis() {
                const fixed = $('#commission-basis').val() === 'fixed';

                $('#commission-amount-field').toggle(fixed);
                $('#commission-rate-field').toggle(!fixed);
            }

            $('#commission-basis').on('change', syncBasis);
            syncBasis();
        });
    </script>
@endpush
